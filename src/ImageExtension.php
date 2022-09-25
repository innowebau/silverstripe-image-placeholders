<?php
namespace Innoweb\ImagePlaceholders;

use BadMethodCallException;
use SilverStripe\Assets\Image_Backend;
use SilverStripe\Assets\InterventionBackend;
use SilverStripe\Core\Environment;
use SilverStripe\Core\Extension;

class ImageExtension extends Extension
{
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
