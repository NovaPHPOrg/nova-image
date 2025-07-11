<?php

declare(strict_types=1);

namespace nova\plugin\image\adapters;

use GdImage;
use nova\framework\http\Response;

/**
 * GDAdapter —— 基于 PHP-GD 的图片适配器实现
 *
 * 目标
 * 1. 尽可能读写 GD 支持的光栅格式（JPEG/PNG/GIF/BMP/WBMP/WebP/AVIF/ICO/XBM/TIFF/PNM…）
 * 2. 保持链式调用；所有变更方法均返回 $this
 * 3. 透明通道安全：对 PNG/WebP/AVIF/GIF 保留 alpha
 * 4. 实现 AdapterInterface 约定的全部接口
 */
class GDAdapter implements AdapterInterface
{
    /** @var resource|GdImage GD 句柄 */
    protected $img;

    /* ------------------------------------------------------------------ */
    /*  构造 / 加载                                                       */
    /* ------------------------------------------------------------------ */
    private string $mime;

    /**
     * @param  string                                       $path 图片文件路径
     * @throws \InvalidArgumentException| \RuntimeException
     */
    public function __construct(string $path)
    {
        $this->load($path);
    }

    /**
     * 加载位图到 GD 资源
     *
     * - 先使用各格式专用的 imagecreatefromXX
     * - 若遇到 TIFF / PNM 等非常见格式，则回退到 imagecreatefromstring
     * - 对不支持的格式抛出 RuntimeException（包含格式名方便排查）
     *
     * @throws \InvalidArgumentException| \RuntimeException
     */
    public function load(string $path): void
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException("文件不存在: $path");
        }
        $info = getimagesize($path);
        if (!$info) {
            throw new \InvalidArgumentException("无法解析图片头: $path");
        }
        $type = $info[2];

        $this->img = match (true) {
            $type === IMAGETYPE_JPEG => imagecreatefromjpeg($path),

            $type === IMAGETYPE_PNG  => tap(
                imagecreatefrompng($path),
                fn ($im) => imagesavealpha($im, true)
            ),

            $type === IMAGETYPE_GIF  => imagecreatefromgif($path),

            $type === IMAGETYPE_BMP  => self::checkFn('imagecreatefrombmp')
                ? imagecreatefrombmp($path)
                : self::fail('BMP'),

            $type === IMAGETYPE_WBMP => self::checkFn('imagecreatefromwbmp')
                ? imagecreatefromwbmp($path)
                : self::fail('WBMP'),

            $type === IMAGETYPE_WEBP => self::checkFn('imagecreatefromwebp')
                ? imagecreatefromwebp($path)
                : self::fail('WebP'),

            defined('IMAGETYPE_AVIF') && $type === IMAGETYPE_AVIF
            => self::checkFn('imagecreatefromavif')
                ? imagecreatefromavif($path)
                : self::fail('AVIF'),

            defined('IMAGETYPE_ICO') && $type === IMAGETYPE_ICO
            => $this->loadIco($path),

            $type === IMAGETYPE_XBM  => self::checkFn('imagecreatefromxbm')
                ? imagecreatefromxbm($path)
                : self::fail('XBM'),

            /* TIFF(II/MM) → 尝试字符串解码 */
            ($type === IMAGETYPE_TIFF_II || $type === IMAGETYPE_TIFF_MM)
            => $this->loadViaString($path, 'TIFF'),

            /* 其他：直接尝试 imagecreatefromstring */
            default => $this->loadViaString($path, "格式代码 $type"),
        };
    }

    /* ------------------------- 私有小工具 ------------------------------ */

    /** 检查函数是否存在（GD 编译选项差异用） */
    private static function checkFn(string $fn): bool
    {
        return function_exists($fn);
    }

    /** 抛标准化异常并标明缺失格式 */
    private static function fail(string $fmt): never
    {
        throw new \RuntimeException("$fmt 格式在当前 GD 编译中不受支持");
    }

    /** ICO 读取：仅取首帧（GD 只识别第一帧 PNG） */
    protected function loadIco(string $path)
    {
        $data = file_get_contents($path);
        $im = imagecreatefromstring($data);
        if ($im === false) {
            self::fail('ICO');
        }
        imagesavealpha($im, true);
        return $im;
    }

    /** 通用字符串解码加载（TIFF/PNM 等） */
    protected function loadViaString(string $path, string $label)
    {
        $data = file_get_contents($path);
        $im = imagecreatefromstring($data);
        if ($im === false) {
            self::fail($label);
        }
        imagesavealpha($im, true);
        return $im;
    }

    /* ------------------------------------------------------------------ */
    /*  基本信息                                                           */
    /* ------------------------------------------------------------------ */

    /** @inheritDoc */
    public function width(): int
    {
        return imagesx($this->img);
    }

    /** @inheritDoc */
    public function height(): int
    {
        return imagesy($this->img);
    }

    /** @inheritDoc */
    public function resource()
    {
        return $this->img;
    }

    /* ========================= 下面开始核心操作 ======================== */
    /* ------------------------------------------------------------------ */
    /*  核心操作 —— 所有方法均返回 $this 可链式调用                        */
    /* ------------------------------------------------------------------ */

    /**
     * 等比或强制缩放至指定宽高
     * @param int $width  目标宽度
     * @param int $height 目标高度
     */
    public function resize(int $width, int $height): static
    {
        $dst = imagecreatetruecolor($width, $height);
        /* 保留透明通道 */
        imagesavealpha($dst, true);
        imagefill(
            $dst,
            0,
            0,
            imagecolorallocatealpha($dst, 0, 0, 0, 127)
        );

        imagecopyresampled(
            $dst,
            $this->img,
            0,
            0,
            0,
            0,
            $width,
            $height,
            $this->width(),
            $this->height()
        );
        imagedestroy($this->img);
        $this->img = $dst;
        return $this;
    }

    /**
     * 裁剪
     */
    public function crop(int $x, int $y, int $w, int $h): static
    {
        $dst = imagecreatetruecolor($w, $h);
        imagesavealpha($dst, true);
        imagefill(
            $dst,
            0,
            0,
            imagecolorallocatealpha($dst, 0, 0, 0, 127)
        );

        imagecopyresampled(
            $dst,
            $this->img,
            0,
            0,
            $x,
            $y,
            $w,
            $h,
            $w,
            $h
        );
        imagedestroy($this->img);
        $this->img = $dst;
        return $this;
    }

    /**
     * 旋转
     * @param float $angle 逆时针角度
     */
    public function rotate(float $angle): static
    {
        $bg = imagecolorallocatealpha($this->img, 0, 0, 0, 127);
        $rot = imagerotate($this->img, $angle, $bg);
        imagesavealpha($rot, true);
        imagedestroy($this->img);
        $this->img = $rot;
        return $this;
    }

    /**
     * 翻转
     * @param string $mode horizontal | vertical | both
     */
    public function flip(string $mode = 'horizontal'): static
    {
        if (!function_exists('imageflip')) {
            return $this;               // 兼容旧 GD
        }
        $map = [
            'horizontal' => IMG_FLIP_HORIZONTAL,
            'vertical'   => IMG_FLIP_VERTICAL,
            'both'       => IMG_FLIP_BOTH
        ];
        imageflip($this->img, $map[$mode] ?? IMG_FLIP_HORIZONTAL);
        return $this;
    }

    /**
     * 水印叠加
     * @param string $overlayPath 水印 PNG 路径
     * @param string $position    top-left|top-right|bottom-left|bottom-right|center
     * @param float  $opacity     0–1 透明度（GD 直接 copy 不支持 alpha 融合，此处忽略）
     */
    public function watermark(
        string $overlayPath,
        string $position = 'bottom-right',
        float  $opacity  = 0.4
    ): static {
        $wm = (new self($overlayPath))->resource();
        $w  = imagesx($wm);
        $h  = imagesy($wm);
        [$x, $y] = $this->calcPosition($position, $w, $h);

        imagealphablending($this->img, true);
        imagesavealpha($this->img, true);

        imagecopy($this->img, $wm, $x, $y, 0, 0, $w, $h);
        imagedestroy($wm);
        return $this;
    }

    /**
     * 文字写入
     * @param string $text     文本
     * @param int    $size     字号
     * @param string $hexColor 颜色（#rrggbb）
     * @param string $fontPath TTF 字体路径
     */
    public function text(
        string $text,
        int    $size      = 16,
        string $hexColor  = '#ffffff',
        string $fontPath  = '',
        int    $x         = 0,
        int    $y         = 0
    ): static {
        if (!file_exists($fontPath)) {
            throw new \InvalidArgumentException("字体不存在: $fontPath");
        }
        $rgb = sscanf($hexColor, '#%02x%02x%02x');
        $col = imagecolorallocate($this->img, $rgb[0], $rgb[1], $rgb[2]);

        imagettftext(
            $this->img,
            $size,
            0,
            $x,
            $y + $size,
            $col,
            $fontPath,
            $text
        );
        return $this;
    }

    /**
     * 通用 GD 滤镜封装
     */
    public function filter(int $filter, ...$args): static
    {
        imagefilter($this->img, $filter, ...$args);
        return $this;
    }

    /* ================================================================== */
    /*  进阶实用操作（缩略图 / EXIF 方向 / 滤镜 / 分析）                   */
    /* ================================================================== */

    /**
     * 生成缩略图
     *  - $crop=false：按最长边等比缩放，保持完整
     *  - $crop=true ：先等比放大覆盖，再从中心裁剪（适合头像方图）
     */
    public function thumbnail(int $maxWidth, int $maxHeight, bool $crop = false): static
    {
        $srcW = $this->width();
        $srcH = $this->height();

        /* 计算目标尺寸 */
        if ($crop) {
            // 覆盖式缩放：取较大的比例
            $scale = max($maxWidth / $srcW, $maxHeight / $srcH);
            $tmpW  = (int) ceil($srcW * $scale);
            $tmpH  = (int) ceil($srcH * $scale);
            $this->resize($tmpW, $tmpH);

            // 再居中裁剪
            $x = (int) floor(($tmpW - $maxWidth) / 2);
            $y = (int) floor(($tmpH - $maxHeight) / 2);
            $this->crop($x, $y, $maxWidth, $maxHeight);
        } else {
            // 适配式缩放：取较小的比例
            $scale = min($maxWidth / $srcW, $maxHeight / $srcH, 1);
            $dstW  = (int) floor($srcW * $scale);
            $dstH  = (int) floor($srcH * $scale);
            $this->resize($dstW, $dstH);
        }
        return $this;
    }

    /**
     * 根据 EXIF Orientation 自动旋转 / 翻转
     * 注意：只有 JPEG 会带 Orientation；若未检测到则原样返回
     */
    public function autoOrient(): static
    {
        if (!function_exists('exif_read_data') || !isset($this->sourcePath)) {
            return $this;
        }
        $exif = @exif_read_data($this->sourcePath);
        if (!isset($exif['Orientation'])) {
            return $this;
        }
        $ori = (int) $exif['Orientation'];
        return match ($ori) {
            2 => $this->flip('horizontal'),
            3 => $this->rotate(180),
            4 => $this->flip('vertical'),
            5 => $this->rotate(270)->flip('horizontal'),
            6 => $this->rotate(270),
            7 => $this->rotate(90)->flip('horizontal'),
            8 => $this->rotate(90),
            default => $this,
        };
    }

    /** 灰度化 */
    public function grayscale(): static
    {
        return $this->filter(IMG_FILTER_GRAYSCALE);
    }

    /** 反色 */
    public function invert(): static
    {
        return $this->filter(IMG_FILTER_NEGATE);
    }

    /** 亮度调整（-255~+255） */
    public function brightness(int $level): static
    {
        return $this->filter(IMG_FILTER_BRIGHTNESS, max(-255, min(255, $level)));
    }

    /** 对比度调整（-100~+100，正值降低对比度） */
    public function contrast(int $level): static
    {
        return $this->filter(IMG_FILTER_CONTRAST, max(-100, min(100, $level)));
    }

    /** 高斯模糊；$radius 次叠加原生 GAUSSIAN_BLUR 滤镜 */
    public function blur(float $radius = 1.0): static
    {
        $times = max(1, (int) round($radius));
        for ($i = 0; $i < $times; $i++) {
            $this->filter(IMG_FILTER_GAUSSIAN_BLUR);
        }
        return $this;
    }

    /** 锐化（卷积方式） */
    public function sharpen(float $radius = 0.0, float $sigma = 1.0): static
    {
        // 简易 3×3 锐化卷积核
        $matrix = [
            [-1, -1, -1],
            [-1, 16, -1],
            [-1, -1, -1],
        ];
        $div = 8; // 越大锐化越弱
        imageconvolution($this->img, $matrix, $div, 0);
        return $this;
    }

    /**
     * 提取主色调
     *  - 先缩小到 50×50，统计出现频次最高的像素
     *  - 返回十六进制色值数组
     */
    public function palette(int $count = 5): array
    {
        $sample = imagecreatetruecolor(50, 50);
        imagecopyresampled($sample, $this->img, 0, 0, 0, 0, 50, 50, $this->width(), $this->height());

        $freq = [];
        for ($x = 0; $x < 50; $x++) {
            for ($y = 0; $y < 50; $y++) {
                $rgb = imagecolorat($sample, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $hex = sprintf('#%02x%02x%02x', $r, $g, $b);
                $freq[$hex] = ($freq[$hex] ?? 0) + 1;
            }
        }
        arsort($freq);
        imagedestroy($sample);
        return array_slice(array_keys($freq), 0, $count);
    }

    /**
     * 生成简单直方图：返回 R/G/B 三通道各 256 桶计数
     */
    public function histogram(): array
    {
        $hist = [
            'r' => array_fill(0, 256, 0),
            'g' => array_fill(0, 256, 0),
            'b' => array_fill(0, 256, 0),
        ];
        $w = $this->width();
        $h = $this->height();
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                $rgb = imagecolorat($this->img, $x, $y);
                $hist['r'][($rgb >> 16) & 0xFF]++;
                $hist['g'][($rgb >> 8) & 0xFF]++;
                $hist['b'][$rgb & 0xFF]++;
            }
        }
        return $hist;
    }

    /* ==================== 接下来是保存 / 编码 / 压缩等 ==================== */
    /* ------------------------------------------------------------------ */
    /*  保存 / 输出 / 编码                                                */
    /* ------------------------------------------------------------------ */

    /**
     * 保存到文件
     */
    public function save(string $path, int $quality = 90): void
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        match ($ext) {
            'jpg', 'jpeg' => imagejpeg($this->img, $path, $quality),
            'png'         => imagepng($this->img, $path),
            'gif'         => imagegif($this->img, $path),
            'bmp'         => self::checkFn('imagebmp') ? imagebmp($this->img, $path) : null,
            'wbmp'        => self::checkFn('imagewbmp') ? imagewbmp($this->img, $path) : null,
            'webp'        => self::checkFn('imagewebp') ? imagewebp($this->img, $path, $quality) : null,
            'avif'        => self::checkFn('imageavif') ? imageavif($this->img, $path, $quality) : null,
            /* ICO：直接写入 PNG 数据，让 Vista+ 识别 */
            'ico'         => imagepng($this->img, $path),
            default       => throw new \RuntimeException("未知或不支持的格式: .$ext"),
        };

        /* 记录当前格式 */
        $this->mime = $this->extToMime($ext);
    }

    /**
     * 直接输出到浏览器
     */
    public function output(string $mime = 'image/png', int $quality = 90): Response
    {
        return Response::asRaw(
            $this->encode($mime, $quality),
            [
                "Content-Type" =>  $this->mime,
            ]
        );
    }

    /**
     * 转 Base64
     */
    public function toBase64(
        string $mime = 'image/png',
        int    $quality = 90,
        bool   $dataUri = false
    ): string {
        $raw = $this->encode($mime, $quality);
        $b64 = base64_encode($raw);
        return $dataUri ? "data:$mime;base64,$b64" : $b64;
    }

    /**
     * 通用压缩 —— 根据环境优先转 WebP/AVIF
     *
     * @param int   $quality 0-100
     * @param array $options ['format'=>null|'webp'|'avif', 'stripMeta'=>bool]
     */
    public function compress(int $quality = 80, array $options = []): static
    {
        $fmt = $options['format'] ?? null;

        /* 自动选格式 */
        if ($fmt === null) {
            if (self::checkFn('imagewebp')) {
                $fmt = 'webp';
            } elseif (self::checkFn('imageavif')) {
                $fmt = 'avif';
            } else {
                $fmt = $this->mimeToExt($this->mime);
            }
        }

        /* 重新编码到内存 */
        $raw = $this->encode($this->extToMime($fmt), $quality);
        $im  = imagecreatefromstring($raw);
        if ($im === false) {
            throw new \RuntimeException("无法对图片重新编码为 $fmt");
        }
        imagesavealpha($im, true);

        imagedestroy($this->img);
        $this->img  = $im;
        $this->mime = $this->extToMime($fmt);
        return $this;
    }

    /**
     * 返回当前 mime
     */
    public function mime(): string
    {
        return $this->mime;
    }

    /* --------------------------- 内部编码 ----------------------------- */

    /** 把 GD 资源编码为字符串 */
    protected function encode(string $mime, int $quality): string
    {
        ob_start();
        match ($mime) {
            'image/jpeg'                     => imagejpeg($this->img, null, $quality),
            'image/png'                      => imagepng($this->img),
            'image/gif'                      => imagegif($this->img),
            'image/bmp'                      => self::checkFn('imagebmp') ? imagebmp($this->img) : null,
            'image/vnd.wap.wbmp',
            'image/wbmp'                     => self::checkFn('imagewbmp') ? imagewbmp($this->img) : null,
            'image/webp'                     => self::checkFn('imagewebp') ? imagewebp($this->img, null, $quality) : null,
            'image/avif'                     => self::checkFn('imageavif') ? imageavif($this->img, null, $quality) : null,
            'image/x-icon',
            'image/vnd.microsoft.icon'       => imagepng($this->img),
            default => ob_end_clean() && throw new \RuntimeException("encode(): 不支持的 mime $mime"),
        };
        return ob_get_clean();
    }

    /* -------------------------- 位置计算 ----------------------------- */

    /**
     * 计算水印定位
     */
    protected function calcPosition(string $pos, int $w, int $h): array
    {
        $imgW = $this->width();
        $imgH = $this->height();
        return match ($pos) {
            'top-left'     => [0, 0],
            'top-right'    => [$imgW - $w, 0],
            'bottom-left'  => [0, $imgH - $h],
            'center'       => [($imgW - $w) / 2, ($imgH - $h) / 2],
            default        => [$imgW - $w, $imgH - $h], // bottom-right
        };
    }

    /* --------------------------- 工具函数 ---------------------------- */

    /** 扩展名 → mime */
    private function extToMime(string $ext): string
    {
        return match ($ext) {
            'jpg','jpeg' => 'image/jpeg',
            'png'        => 'image/png',
            'gif'        => 'image/gif',
            'bmp'        => 'image/bmp',
            'wbmp'       => 'image/vnd.wap.wbmp',
            'webp'       => 'image/webp',
            'avif'       => 'image/avif',
            'ico'        => 'image/x-icon',
            default      => 'image/png',
        };
    }

    /** mime → 扩展名 */
    private function mimeToExt(string $mime): string
    {
        return match ($mime) {
            'image/jpeg'                      => 'jpg',
            'image/png'                       => 'png',
            'image/gif'                       => 'gif',
            'image/bmp'                       => 'bmp',
            'image/vnd.wap.wbmp', 'image/wbmp' => 'wbmp',
            'image/webp'                      => 'webp',
            'image/avif'                      => 'avif',
            'image/x-icon',
            'image/vnd.microsoft.icon'        => 'ico',
            default                           => 'png',
        };
    }

    /* ------------------------------------------------------------------ */
    /*  析构：释放资源                                                     */
    /* ------------------------------------------------------------------ */

    public function __destruct()
    {
        if ($this->img && is_resource($this->img)) {
            imagedestroy($this->img);
        }
    }

}

/* -------------------------------------------------------------------- */
/*  tap() 小助手 —— 仅当外部未提前定义时注入                            */
/* -------------------------------------------------------------------- */
if (!function_exists('nova\\plugin\\image\\adapters\\tap')) {
    function tap($value, callable $fn)
    {
        $fn($value);
        return $value;
    }
}
