<?php
namespace Innoweb\ImagePlaceholders;

use BadMethodCallException;
use Exception;
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
            $width = null;
            $height = null;
            $divisor = null;
            $image = null;
            $resource = null;
            $stream = null;
            $imageData = null;

            return $backendClone;
        });
    }

    /**
     * LCP LQIP based on https://csswizardry.com/2023/09/the-ultimate-lqip-lcp-technique/.
     * Please use tractorcow/silverstripe-image-formatter before LCPLQIP, otherwise the minimum size calculations
     * will be incorrect.
     *
     * @return InterventionBackend image
     */
    public function LCPLQIP()
    {
        $variant = $this->getOwner()->variantName(__FUNCTION__);
        return $this->getOwner()->manipulateImage($variant, function (Image_Backend $backend) {
            $backendClone = clone $backend;
            $resource = clone $backend->getImageResource();

            // get size
            $width = $resource->getWidth();
            $height = $resource->getHeight();

            // encode with lowest quality
            $quality = 0;
            $result = $resource->encode($format, $quality);

            // intervention image can be casted to string to access data
            $size = strlen((string) $result);

            // re-calculate result with increased quality if the image is too small
            $minSize = self::min_size($width, $height);
            while ($size < $minSize && $quality < 90) {
                $quality += 5;
                $result = $resource->encode($format, $quality);
                $size = strlen((string) $result);
            }

            // update backend with result
            $backendClone->setQuality($quality);

            $backendClone->setImageResource($resource);
            return $backendClone;
        });
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
