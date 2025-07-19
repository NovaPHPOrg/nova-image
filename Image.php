<?php

declare(strict_types=1);

namespace nova\plugin\image;

use nova\plugin\image\adapters\AdapterInterface;
use nova\plugin\image\adapters\GDAdapter;
use RuntimeException;

/**
 * Image – 轻量级、链式调用的图像处理助手（支持GD或Imagick）
 * ============================================================
 *
 * 这是一个图像处理的门面类，提供了简洁的API来操作图像。它通过适配器模式
 * 支持多种图像处理引擎，目前主要支持GD扩展。
 *
 * ## 设计特点
 * - **轻量级**：最小化依赖，专注于核心图像操作
 * - **链式调用**：所有变换方法都返回适配器实例，支持流式操作
 * - **引擎无关**：通过适配器接口抽象底层实现
 * - **类型安全**：使用严格类型声明
 *
 * ## 使用示例
 * ```php
 * // 基本用法
 * Image::from('input.png')
 *     ->resize(512, 512)
 *     ->watermark('logo.png')
 *     ->quality(85)
 *     ->save('output.png');
 *
 * // 缩略图生成
 * Image::from('photo.jpg')
 *     ->thumbnail(200, 200)
 *     ->compress(80, ['format' => 'webp'])
 *     ->save('thumb.webp');
 *
 * // 直接输出到浏览器
 * Image::from('image.png')
 *     ->resize(800, 600)
 *     ->output('image/jpeg', 90);
 * ```
 *
 * ## 支持的图像格式
 * - **输入**：JPEG, PNG, GIF, BMP, WBMP, WebP, AVIF, ICO, XBM, TIFF, PNM
 * - **输出**：JPEG, PNG, GIF, BMP, WBMP, WebP, AVIF
 *
 * ## 注意事项
 * - 需要PHP GD扩展支持
 * - 某些格式（如AVIF、WebP）需要GD编译时包含相应支持
 * - 建议在生产环境中使用WebP或AVIF格式以获得更好的压缩效果
 *
 * @package nova\plugin\image
 * @author Nova Framework
 * @since 1.0.0
 */
class Image
{
    /**
     * 图像处理适配器实例
     *
     * 该属性持有具体的图像处理适配器，实现了AdapterInterface接口。
     * 根据系统环境自动选择合适的适配器（目前主要支持GD）。
     *
     * @var AdapterInterface
     */
    protected AdapterInterface $adapter;

    /* --------------------------------------------------------------------- */
    /*  初始化与引导                                                         */
    /* --------------------------------------------------------------------- */

    /**
     * 构造函数 - 创建图像处理实例
     *
     * 根据系统环境自动选择合适的图像处理引擎。目前主要支持GD扩展，
     * 如果系统没有安装GD扩展，将抛出RuntimeException异常。
     *
     * @param  string           $path 图像文件路径
     * @throws RuntimeException 当没有支持的图像处理库时抛出
     *
     * @example
     * ```php
     * // 创建图像实例
     * $image = new Image('photo.jpg');
     *
     * // 进行图像处理
     * $image->adapter->resize(800, 600)->save('resized.jpg');
     * ```
     */
    public function __construct(string $path)
    {
        if (extension_loaded('gd')) {
            $this->adapter = new GDAdapter($path);
        } else {
            throw new RuntimeException("没有支持的图片库，请安装gd。");
        }
    }

    /**
     * 静态工厂方法 - 从文件路径创建图像处理实例
     *
     * 这是一个便捷的静态方法，用于快速创建图像处理实例。
     * 返回适配器实例，可以直接进行链式操作。
     *
     * @param  string                    $path 图像文件路径
     * @return AdapterInterface          图像处理适配器实例
     * @throws RuntimeException          当没有支持的图像处理库时抛出
     * @throws \InvalidArgumentException 当文件不存在或无法读取时抛出
     *
     * @example
     * ```php
     * // 链式操作示例
     * Image::from('input.png')
     *     ->resize(512, 512)
     *     ->watermark('logo.png', 'bottom-right', 0.5)
     *     ->compress(85, ['format' => 'webp'])
     *     ->save('output.webp');
     *
     * // 获取图像信息
     * $adapter = Image::from('photo.jpg');
     * echo "尺寸: {$adapter->width()} x {$adapter->height()}";
     * echo "MIME类型: {$adapter->mime()}";
     *
     * // 直接输出到浏览器
     * Image::from('image.png')
     *     ->thumbnail(300, 300)
     *     ->output('image/jpeg', 90);
     * ```
     */
    public static function from(string $path): AdapterInterface
    {
        return (new self($path))->adapter;
    }

}
