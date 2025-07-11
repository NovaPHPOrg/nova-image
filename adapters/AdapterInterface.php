<?php

declare(strict_types=1);

namespace nova\plugin\image\adapters;

use nova\framework\http\Response;

/**
 * 适配器顶层接口（AdapterInterface）
 * ==================================
 * 所有位图后端（GD、Imagick、libvips …）都需遵循的 **唯一契约**，
 * 让业务层与具体实现彻底解耦。只要实现了本接口，就能在任何地方
 * 轻松切换图像引擎，而无需修改调用代码。
 *
 * ## 设计要点
 * 1. **引擎无关**：上层永远不直接依赖 GD/Imagick 等名称。
 * 2. **链式调用**：大多数变换方法返回 `static`，可流式书写。
 * 3. **按需生长**：只有当出现真实需求时才在这里新增方法；此文件
 *    是功能对齐的单一来源。
 *
 * ### 通用压缩
 * 过去分为 `compressJpeg()` / `compressPng()` / … 不同方法，
 * 现统一为 **`compress()`**：
 * ```php
 * $img->compress(80, [
 *     'format'     => 'webp',   // 留空则自动按原格式
 *     'lossless'   => false,
 *     'stripMeta'  => true,     // 移除 EXIF / ICC
 *     'progressive'=> true      // JPEG 渐进式
 * ]);
 * ```
 * 具体引擎内部按可用功能取用最佳策略。
 */
interface AdapterInterface
{
    /* ------------------------------------------------------------------ */
    /*  构造与基本信息                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * 构造函数——加载磁盘上的图片。
     *
     * @param string $path 图片文件路径。
     *
     * @throws \InvalidArgumentException 文件不可读。
     * @throws \RuntimeException         格式不受支持。
     */
    public function __construct(string $path);

    /** 图片宽度（像素）。*/
    public function width(): int;

    /** 图片高度（像素）。*/
    public function height(): int;

    /** 根据当前图像缓冲区推断 MIME（例如 `image/png`）。*/
    public function mime(): string;

    /* ------------------------------------------------------------------ */
    /*  几何操作                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * 强制调整到指定宽高（调用方自行保持宽高比）。
     */
    public function resize(int $width, int $height): static;

    /**
     * 生成缩略图，使其完全落在 $maxWidth × $maxHeight 区域内。
     *
     * @param int  $maxWidth  最大宽度。
     * @param int  $maxHeight 最大高度。
     * @param bool $crop      true = 强裁剪到精确尺寸；false = 等比缩放。
     */
    public function thumbnail(int $maxWidth, int $maxHeight, bool $crop = false): static;

    /** 裁剪指定矩形。*/
    public function crop(int $x, int $y, int $width, int $height): static;

    /** 顺时针旋转若干角度。*/
    public function rotate(float $angle): static;

    /**
     * 翻转图像。
     *
     * @param string $mode `horizontal`、`vertical`、`both` 之一。
     */
    public function flip(string $mode = 'horizontal'): static;

    /** 按 EXIF Orientation 自动旋转到正确方向。*/
    public function autoOrient(): static;

    /* ------------------------------------------------------------------ */
    /*  绘制与合成                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * 叠加水印。
     *
     * @param string $overlayPath 水印图片路径（推荐 PNG）。
     * @param string $position    方位：`top-left`、`top-right`、`bottom-left`、`bottom-right`、`center`。
     * @param float  $opacity     透明度 0‒1。
     */
    public function watermark(string $overlayPath, string $position = 'bottom-right', float $opacity = 0.4): static;

    /**
     * 写文字。
     *
     * @param string $text     UTF‑8 字符串。
     * @param int    $size     字号 pt。
     * @param string $hexColor 颜色 `#rrggbb`。
     * @param string $fontPath TrueType/OpenType 字体文件路径。
     * @param int    $x        左上 X 偏移。
     * @param int    $y        左上 Y 偏移。
     */
    public function text(string $text, int $size = 16, string $hexColor = '#ffffff', string $fontPath = '', int $x = 0, int $y = 0): static;

    /** 引擎原生滤镜包装。*/
    public function filter(int $filter, ...$args): static;

    /* 快捷色彩 / 锐化 / 模糊 */
    public function grayscale(): static;                    // 灰度
    public function invert(): static;                       // 反色
    public function brightness(int $level): static;         // 亮度 −255…+255
    public function contrast(int $level): static;           // 对比度 −100…+100
    public function blur(float $radius = 1.0): static;      // 高斯模糊
    public function sharpen(float $radius = 0.0, float $sigma = 1.0): static; // 锐化

    /* ------------------------------------------------------------------ */
    /*  压缩与优化                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * 通用压缩函数。
     *
     * * 根据当前或指定格式执行最优压缩策略。
     * * `$options` 支持（各键可选）：
     *   - `format`      => "png" / "jpeg" / "webp" / "avif" 等；
     *                        **留空时由子类自动决定**：若运行环境支持 WebP/AVIF
     *                        则优先转换为现代格式，否则保持原格式。
     *   - `lossless`    => bool   对 WebP/AVIF 启用无损
     *   - `stripMeta`   => bool   移除 EXIF/ICC/IPTC 等元数据
     *   - `progressive` => bool   JPEG 是否渐进式
     */
    public function compress(int $quality = 80, array $options = []): static;

    /* ------------------------------------------------------------------ */
    /*  图像分析                                                           */
    /* ------------------------------------------------------------------ */

    /** 主色调数组，按出现频率排序（`#rrggbb`）。*/
    public function palette(int $count = 5): array;

    /** 直方图数据 `[r, g, b] => count`。*/
    public function histogram(): array;

    /* ------------------------------------------------------------------ */
    /*  持久化与编码                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * 保存到磁盘，格式依据文件扩展名自动选择。
     *
     * @param string $path    目标路径。
     * @param int    $quality 有损格式质量 0‒100。
     */
    public function save(string $path, int $quality = 90): void;

    /**
     * 直接输出到客户端（echo）。
     *
     * @param string $mime    目标 MIME。
     * @param int    $quality 有损质量。
     */
    public function output(string $mime = 'image/png', int $quality = 90): Response;

    /** Base64 编码，可选 `data:` URI 前缀。*/
    public function toBase64(string $mime = 'image/png', int $quality = 90, bool $dataUri = false): string;

    /* ------------------------------------------------------------------ */
    /*  底层句柄                                                           */
    /* ------------------------------------------------------------------ */

    /** 返回底层 GD 资源或 Imagick 对象。*/
    public function resource();
}
