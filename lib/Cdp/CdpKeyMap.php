<?php

declare(strict_types=1);

namespace Facebook\WebDriver\Cdp;

use Facebook\WebDriver\WebDriverKeys;

/**
 * Translates WebDriver's private-use key codepoints into CDP key events.
 *
 * WebDriver encodes non-printable keys as characters in the U+E000 private use
 * area. CDP instead wants a key name plus a virtual key code - without the
 * latter a page's keydown handler sees no recognisable Enter or Tab.
 */
final class CdpKeyMap
{
    /**
     * @var array<string, array{key: string, code: string, windowsVirtualKeyCode: int}>
     */
    private const KEYS = [
        WebDriverKeys::BACKSPACE => ['key' => 'Backspace', 'code' => 'Backspace', 'windowsVirtualKeyCode' => 8],
        WebDriverKeys::TAB => ['key' => 'Tab', 'code' => 'Tab', 'windowsVirtualKeyCode' => 9],
        WebDriverKeys::RETURN_KEY => ['key' => 'Enter', 'code' => 'Enter', 'windowsVirtualKeyCode' => 13],
        WebDriverKeys::ENTER => ['key' => 'Enter', 'code' => 'Enter', 'windowsVirtualKeyCode' => 13],
        WebDriverKeys::SHIFT => ['key' => 'Shift', 'code' => 'ShiftLeft', 'windowsVirtualKeyCode' => 16],
        WebDriverKeys::CONTROL => ['key' => 'Control', 'code' => 'ControlLeft', 'windowsVirtualKeyCode' => 17],
        WebDriverKeys::ALT => ['key' => 'Alt', 'code' => 'AltLeft', 'windowsVirtualKeyCode' => 18],
        WebDriverKeys::PAUSE => ['key' => 'Pause', 'code' => 'Pause', 'windowsVirtualKeyCode' => 19],
        WebDriverKeys::ESCAPE => ['key' => 'Escape', 'code' => 'Escape', 'windowsVirtualKeyCode' => 27],
        WebDriverKeys::PAGE_UP => ['key' => 'PageUp', 'code' => 'PageUp', 'windowsVirtualKeyCode' => 33],
        WebDriverKeys::PAGE_DOWN => ['key' => 'PageDown', 'code' => 'PageDown', 'windowsVirtualKeyCode' => 34],
        WebDriverKeys::END => ['key' => 'End', 'code' => 'End', 'windowsVirtualKeyCode' => 35],
        WebDriverKeys::HOME => ['key' => 'Home', 'code' => 'Home', 'windowsVirtualKeyCode' => 36],
        WebDriverKeys::ARROW_LEFT => ['key' => 'ArrowLeft', 'code' => 'ArrowLeft', 'windowsVirtualKeyCode' => 37],
        WebDriverKeys::ARROW_UP => ['key' => 'ArrowUp', 'code' => 'ArrowUp', 'windowsVirtualKeyCode' => 38],
        WebDriverKeys::ARROW_RIGHT => ['key' => 'ArrowRight', 'code' => 'ArrowRight', 'windowsVirtualKeyCode' => 39],
        WebDriverKeys::ARROW_DOWN => ['key' => 'ArrowDown', 'code' => 'ArrowDown', 'windowsVirtualKeyCode' => 40],
        WebDriverKeys::INSERT => ['key' => 'Insert', 'code' => 'Insert', 'windowsVirtualKeyCode' => 45],
        WebDriverKeys::DELETE => ['key' => 'Delete', 'code' => 'Delete', 'windowsVirtualKeyCode' => 46],
    ];

    /**
     * @return array{key: string, code: string, windowsVirtualKeyCode: int}|null
     *                                                                          null when the character is ordinary printable text
     */
    public static function resolve(string $character): ?array
    {
        return self::KEYS[$character] ?? null;
    }
}
