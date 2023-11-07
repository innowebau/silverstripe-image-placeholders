<?php
namespace Innoweb\ImagePlaceholders;

use BadMethodCallException;
use Intervention\Image\Image;
use Psr\Log\LoggerInterface;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image_Backend;
use SilverStripe\Assets\InterventionBackend;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extension;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBField;

class ImageExtension extends Extension
{
	/**
	 * @var float minimum bits per pixel (BPP) threshold required for LCP LQIP image.
	 * https://chromium.googlesource.com/chromium/src/+/refs/heads/main/docs/speed/metrics_changelog/2023_04_lcp.md
	 * defines this as 0.05, so we give it another 10% to be on the safe side.
	 */
	private static $min_bits_per_pixel = 0.055;

    /**
     * Low Quality Image Placeholder
     *
     * Use css blur over this to mask pixelation:
     * filter: blur(12px);
     * transform: scale(1.15);
     *
     * @return InterventionBackend image
     */
    public function LQIP()
    {
        $variant = $this->getOwner()->variantName(__FUNCTION__);
        return $this->getOwner()->manipulateImage($variant, function (Image_Backend $backend) {
            $backendClone = clone $backend;
            $backendClone->setQuality(1);

            // only use an 8th of the size (basically every 8th pixel, hence use css blur to smoothe it out)
            $width = round($backend->getWidth() / 8, 0);
            $height = round($backend->getHeight() / 8, 0);

            return $backendClone->resize($width, $height);
        });
    }

    /**
     * Grey Image Placeholder
     *
     * @param int $red
     * @param int $green
     * @param int $blue
     * @return InterventionBackend image
     */
    public function GIP($red = 230, $green = 230, $blue = 230)
    {
		$variant = $this->getOwner()->variantName(__FUNCTION__);
        return $this->getOwner()->manipulateImage($variant, function (Image_Backend $backend) use ($red, $green, $blue) {
            $backendClone = clone $backend;
            $backendClone->setQuality(1);

            $resource = clone $backend->getImageResource();

            // calculate the smallest size with the correct aspect ratio
            $width = $resource->getWidth();
            $height = $resource->getHeight();
            $divisor = self::gcd($width, $height);
            $width = $width / $divisor;
            $height = $height / $divisor;

            // resize image
            $resource = $resource->resize($width, $height);

            // generate uniform image stream
            $image = imagecreate($width, $height);
            // allocate colour
            imagecolorallocate($image, $red, $green, $blue);
            // use memory stream to get the output
            $stream = fopen('php://temp', 'r+');
            imagepng($image, $stream);
            rewind($stream);
            $imageData = stream_get_contents($stream);
            // fill image with newly generated image data
            $resource = $resource->fill($imageData);
            // set resource
            $backendClone->setImageResource($resource);

            // release memory
            imagedestroy($image); // noop since PHP 8
            unset($width);
            unset($height);
            unset($divisor);
            unset($image);
            unset($resource);
            unset($stream);
            unset($imageData);

            return $backendClone;
        });
    }

	/**
	 * LCP LQIP based on https://csswizardry.com/2023/09/the-ultimate-lqip-lcp-technique/.
	 * Converts the file to a new format (e.g. avif, webp) like tractorcow/silverstripe-image-formatter
	 * does. This is necessary because we can't make changes to a formatted image after it is saved because SS
	 * doesn't allow manipulating files with a different extension than the original. That's why we have to do the
	 * formatting and LQIP  in one go.
	 *
	 * @param string $format new file extension
	 * @return File|DBFile
	 */
	public function LCPLQIP(string $format = null)
	{
		if (!$this->getOwner()->getIsImage()) {
			throw new BadMethodCallException("Format can only be called on images");
		}

		// Can't convert if it doesn't exist
		if (!$this->getOwner()->exists()) {
			return null;
		}

		Environment::setMemoryLimitMax('512M');
		Environment::increaseMemoryLimitTo('512M');
		Environment::increaseTimeLimitTo();

		// get new variant string
		$variant = $this->getOwner()->variantName(__FUNCTION__, $format);
		$existingVariant = $this->getOwner()->getVariant();
		if ($existingVariant) {
			$variant = $existingVariant . '_' . $variant;
		}

		// Check asset details
		$filename = $this->getOwner()->getFilename();
		$hash = $this->getOwner()->getHash();

		// Skip if file converted to same extension
		$extension = $this->getOwner()->getExtension();
		$newFilename = $filename;
		if (is_null($format)) {
			$format = $extension;
		} elseif (strcasecmp($extension, $format) !== 0) {
			$newFilename = substr($filename, 0, -strlen($extension)) . strtolower($format);
		}

		// Create this asset in the store if it doesn't already exist,
		// otherwise use the existing variant
		/** @var AssetStore $store */
		$store = Injector::inst()->get(AssetStore::class);
		$backendClone = null;
		if ($store->exists($newFilename, $hash, $variant)) {
			$tuple = [
				'Filename' => $newFilename,
				'Hash'     => $hash,
				'Variant'  => $variant
			];
		} elseif (!$this->getOwner()->getAllowGeneration()) {
			// Circumvent image generation if disabled
			return null;
		} else {
			// Ask intervention to re-save in a new format
			/** @var Image_Backend $backend */
			$backend = $this->getOwner()->getImageBackend();

			/** @var Image $resource intervention image */
			$resource = clone $backend->getImageResource();
			if (!$resource) {
				Injector::inst()->get(LoggerInterface::class)->error('no resource');
				return null;
			}

			$backendClone = clone $backend;

			// get size
			$width = $resource->getWidth();
			$height = $resource->getHeight();

			// pixellate and blur
			$resource = $resource->pixelate(2);
			$resource = $resource->blur(100);
			$resource = $resource->limitColors(255);
			$resource = $resource->interlace();

			// encode result
			$quality = 0;
			$result = $resource->encode($format, $quality);

			// intervention image can be casted to string to access data
			$size = strlen((string) $result);

			// re-calculate result with increased quality if the image is too small
			while ($size < self::min_size($width, $height) && $quality < 90) {
				$quality += 1;
				$result = $resource->encode($format, $quality);
				$size = strlen((string) $result);
			}

			// update backend with result
			$backendClone->setQuality($quality);
			$backendClone->setImageResource($result);

			// release memory
			unset($resource);
			unset($result);

			// Immediately save to new filename
			// Normal asset visibility doesn't work for images with different filenames, so
			// save to public.
			$tuple = $backendClone->writeToStore(
				$store,
				$newFilename,
				$hash,
				$variant,
				[
					'conflict'   => AssetStore::CONFLICT_USE_EXISTING,
					'visibility' => AssetStore::VISIBILITY_PUBLIC,
				]
			);
			if (!$tuple) {
				throw new Exception("Could not convert image {$filename} to {$newFilename}");
			}
		}

		// Store result in new DBFile instance
		/** @var DBFile $file */
		$file = DBField::create_field('DBFile', $tuple);
		$file->setOriginal($this->getOwner());

		// Pass the manipulated image backend down to the resampled image - this allows chained manipulations
		// without having to re-load the image resource from the manipulated file written to disk
		if ($backendClone) {
			$file->setImageBackend($backendClone);
		}

		return $file;
	}

	/**
	 * Calculate minimum size based on bits per pixel (BPP) threshold
	 * @param int $width
	 * @param int $height
	 * @return int bytes
	 */
	private static function min_size($width, $height)
	{
		// divide by 8 to get bytes
		return $width * $height * Config::inst()->get(__CLASS__, 'min_bits_per_pixel') / 8;
	}

	/**
     * Calculate Greatest Common Divisor for two integers
     * @param int $x
     * @param int $y
     * @return int
     */
    private static function gcd($x, $y)
    {
        if ($y == 0) {
            return $x;
        }
        return self::gcd($y, $x%$y);
    }

    /**
     * Return Base64 encoded data URL for this image, e.g. "data:image/png;base64,..."
     *
     * @throws \BadMethodCallException
     * @return NULL|string url
     */
    public function DataURL()
    {
		Environment::setMemoryLimitMax('512M');
		Environment::increaseMemoryLimitTo('512M');

        if (!$this->getOwner()->getIsImage()) {
            throw new BadMethodCallException("Format can only be called on images");
        }

        // Can't convert if it doesn't exist
        if (!$this->getOwner()->exists()) {
            return null;
        }

        $stream = $this->getOwner()->getStream();
        if (!$stream) {
            return null;
        }

        $imageData = stream_get_contents($stream);
        return 'data:' . $this->getOwner()->getMimeType() . ';base64,' . base64_encode($imageData);
    }
}
