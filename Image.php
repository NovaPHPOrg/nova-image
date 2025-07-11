<?php

declare(strict_types=1);

namespace nova\plugin\image;

use nova\plugin\image\adapters\AdapterInterface;
use nova\plugin\image\adapters\GDAdapter;
use RuntimeException;

/**
 * Image – minimalistic, chainable image helper (GD or Imagick)
 *
 * Usage example:
 *   Image::from('in.png')->resize(512)->watermark('logo.png')->quality(85)->save('out.png');
 */
class Image
{
    protected AdapterInterface $adapter;

    /* --------------------------------------------------------------------- */
    /*  Bootstrap                                                           */
    /* --------------------------------------------------------------------- */

    public function __construct(string $path)
    {
        if (extension_loaded('gd')) {
            $this->adapter = new GDAdapter($path);
        } else {
            throw new RuntimeException("没有支持的图片库，请安装gd。");
        }
    }

    public static function from(string $path): AdapterInterface
    {
        return (new self($path))->adapter;
    }

}
