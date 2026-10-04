<?php
namespace App\Core;

/**
 * PHP 템플릿 렌더러. 템플릿은 server/views/ 아래 .php 파일이다.
 * 템플릿 안에서 layout('user/layout', [...]) 을 부르면 그 레이아웃이 $content 로 본문을 감싼다.
 * section('scripts') ... endsection() 으로 모은 내용은 레이아웃에서 yield_section('scripts') 로 출력한다.
 */
class View
{
    /** @var array|null */
    private static $layout = null;
    /** @var array<string, string> */
    private static $sections = [];
    /** @var string[] */
    private static $sectionStack = [];

    public static function render(string $template, array $data = []): string
    {
        $previousLayout = self::$layout;
        self::$layout = null;
        $content = self::renderFile($template, $data);
        $layout = self::$layout;
        while ($layout !== null) {
            self::$layout = null;
            $content = self::renderFile($layout['name'], array_merge($data, $layout['vars'], ['content' => $content]));
            $layout = self::$layout;
        }
        self::$layout = $previousLayout;

        return $content;
    }

    public static function renderPartial(string $template, array $data = []): string
    {
        $previousLayout = self::$layout;
        $out = self::renderFile($template, $data);
        self::$layout = $previousLayout;

        return $out;
    }

    public static function setLayout(string $name, array $vars = []): void
    {
        self::$layout = ['name' => $name, 'vars' => $vars];
    }

    public static function startSection(string $name): void
    {
        self::$sectionStack[] = $name;
        ob_start();
    }

    public static function endSection(): void
    {
        $name = array_pop(self::$sectionStack);
        $out = (string) ob_get_clean();
        if ($name !== null) {
            self::$sections[$name] = (isset(self::$sections[$name]) ? self::$sections[$name] : '') . $out;
        }
    }

    public static function getSection(string $name, string $default = ''): string
    {
        return isset(self::$sections[$name]) ? self::$sections[$name] : $default;
    }

    private static function renderFile(string $template, array $data): string
    {
        $file = APP_ROOT . '/views/' . $template . '.php';
        if (!preg_match('#^[a-zA-Z0-9_/\-]+$#', $template) || !is_file($file)) {
            throw new \RuntimeException('화면 템플릿이 없다: ' . $template);
        }
        $level = ob_get_level();
        ob_start();
        try {
            (static function ($__file, $__data) {
                extract($__data, EXTR_SKIP);
                include $__file;
            })($file, $data);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
