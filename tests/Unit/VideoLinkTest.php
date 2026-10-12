<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Courses\VideoLink;
use PHPUnit\Framework\TestCase;

final class VideoLinkTest extends TestCase
{
    public function testRecognisesYouTubeAndVimeoForms(): void
    {
        $forms = [
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtube.com/watch?v=dQw4w9WgXcQ&t=42s',
            'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
            'https://youtu.be/dQw4w9WgXcQ',
            'youtu.be/dQw4w9WgXcQ',
            'https://www.youtube.com/embed/dQw4w9WgXcQ',
            'https://www.youtube.com/shorts/dQw4w9WgXcQ',
            'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        ];
        foreach ($forms as $url) {
            $video = VideoLink::parse($url);
            $this->assertNotNull($video, $url);
            $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0', $video->embedUrl(), $url);
        }

        $vimeo = VideoLink::parse('https://vimeo.com/76979871');
        $this->assertNotNull($vimeo);
        $this->assertSame('https://player.vimeo.com/video/76979871?dnt=1', $vimeo->embedUrl());
        $this->assertSame('https://vimeo.com/76979871', VideoLink::parse('https://player.vimeo.com/video/76979871')?->watchUrl());
    }

    public function testRefusesEverythingElse(): void
    {
        $refused = [
            'https://evil.example/watch?v=dQw4w9WgXcQ',
            'https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ',
            'https://www.youtube.com/watch?v=short',
            'https://www.youtube.com/watch?v=dQw4w9WgXcQ"><script>',
            'javascript:alert(1)',
            'https://vimeo.com/channels/staffpicks',
            '',
        ];
        foreach ($refused as $url) {
            $this->assertNull(VideoLink::parse($url), $url);
        }
    }
}
