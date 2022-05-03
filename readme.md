# Silverstripe Image Placeholders

## Overview

Provides low quality and greyscale image placeholders including base64 encoded URL data.

## Requirements

* Silverstripe Assets 1.x

## Installation

Install the module using composer:
```
composer require innoweb/silverstripe-image-placeholders dev-master
```
Then run dev/build.

## Usage

Make sure that any resizing is done prior to generating the placeholder image.

Do: `<img src="$Image.Fill(200,200).GIP.DataURL" width="200" height="200">`

Don't: `<img src="$Image.GIP.Fill(200,200).DataURL" width="200" height="200">`

## License

Proprietary
