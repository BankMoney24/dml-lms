<?php

declare(strict_types=1);

namespace App\Modules\LiveClasses\Http;

use App\Core\Http\Response;
use App\Modules\LiveClasses\Classroom\ClassroomSettings;

/**
 * The only pages allowed to load the Jitsi script and frame and to let that
 * frame use the camera, microphone and screen (ADR 0029, 0030). Every other
 * page keeps camera=() and microphone=().
 */
final class RoomHeaders
{
    public static function apply(Response $response, string $domain): Response
    {
        if (!ClassroomSettings::isHostName($domain)) {
            throw new \InvalidArgumentException('Not a classroom server name.');
        }
        $origin = 'https://' . $domain;

        return $response
            ->withHeader('Content-Security-Policy', "default-src 'self'; script-src 'self' $origin; style-src 'self'; img-src 'self' data:; font-src 'self'; "
                . "connect-src 'self'; frame-src $origin; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'")
            ->withHeader('Permissions-Policy', sprintf(
                'camera=(self "%1$s"), microphone=(self "%1$s"), display-capture=(self "%1$s"), fullscreen=(self "%1$s"), autoplay=(self "%1$s"), geolocation=(), interest-cohort=()',
                $origin,
            ));
    }
}
