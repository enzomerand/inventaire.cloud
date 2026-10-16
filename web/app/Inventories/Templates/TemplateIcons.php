<?php

declare(strict_types=1);

namespace App\Inventories\Templates;

/**
 * The icons a template may carry: Tabler icons, by name, from a list kept here.
 *
 * A template file is data written by anybody who sends a pull request, and the icon is turned into a Blade component name
 * (`tabler-<name>`) when the page is drawn: only names from this list are ever put there. Every name is the file name of
 * an icon of the `secondnetwork/blade-tabler-icons` package (a test checks it).
 */
final class TemplateIcons
{
    /** The icons a template may choose from. */
    public const ALLOWED = [
        'apple', 'archive', 'armchair', 'barcode', 'basket', 'bed', 'bike', 'bolt', 'book', 'books', 'bottle', 'box',
        'brush', 'bucket', 'building-community', 'building-store', 'building-warehouse', 'bulb', 'calendar', 'camera',
        'car', 'carrot', 'cheese', 'chef-hat', 'clipboard-list', 'coffee', 'cpu', 'cup', 'device-desktop',
        'device-laptop', 'device-mobile', 'device-tv', 'devices', 'diamond', 'disc', 'egg', 'fish', 'flower',
        'fridge', 'gift', 'glass-full', 'hammer', 'headphones', 'heart', 'heart-handshake', 'home', 'ice-cream',
        'key', 'keyboard', 'lamp', 'leaf', 'lock', 'medal', 'microphone', 'milk', 'movie', 'music', 'notebook', 'nut',
        'package', 'packages', 'paint', 'palette', 'paw', 'photo', 'pizza', 'plant', 'printer', 'receipt', 'router',
        'ruler', 'server', 'shirt', 'shopping-bag', 'shopping-cart', 'sofa', 'soup', 'snowflake', 'tool', 'tools',
        'tools-kitchen-2', 'trophy', 'truck', 'users', 'vinyl', 'wallet',
    ];

    /**
     * Whether the name is one of the allowed icons.
     */
    public static function exists(string $icon): bool
    {
        return in_array($icon, self::ALLOWED, true);
    }

    /**
     * The name of the Blade component that draws the icon (`tabler-home`); the generic `tabler-packages` for a name that is
     * not on the list, so that nothing a file says ever picks a component.
     */
    public static function component(string $icon): string
    {
        return 'tabler-'.(self::exists($icon) ? $icon : 'packages');
    }
}
