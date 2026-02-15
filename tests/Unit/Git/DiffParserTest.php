<?php

/*
 * This file is part of the MatesOfMate Organisation.
 *
 * (c) Johannes Wachter <johannes@sulu.io>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace MatesOfMate\SelfReviewExtension\Tests\Unit\Git;

use MatesOfMate\SelfReviewExtension\Git\ChangedFile;
use MatesOfMate\SelfReviewExtension\Git\DiffParser;
use PHPUnit\Framework\TestCase;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
class DiffParserTest extends TestCase
{
    private DiffParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DiffParser();
    }

    public function testParseEmptyDiff(): void
    {
        $result = $this->parser->parse('');

        $this->assertSame([], $result);
    }

    public function testParseWhitespaceDiff(): void
    {
        $result = $this->parser->parse("   \n\t\n  ");

        $this->assertSame([], $result);
    }

    public function testParseSingleFileModification(): void
    {
        $diff = <<<'DIFF'
            diff --git a/src/Example.php b/src/Example.php
            index 1234567..abcdefg 100644
            --- a/src/Example.php
            +++ b/src/Example.php
            @@ -1,5 +1,6 @@
             <?php

             class Example
             {
            -    public function old(): void {}
            +    public function new(): void {}
            +    public function added(): void {}
             }
            DIFF;

        $result = $this->parser->parse($diff);

        $this->assertCount(1, $result);
        $this->assertSame('src/Example.php', $result[0]->path);
        $this->assertSame(ChangedFile::STATUS_MODIFIED, $result[0]->status);
        $this->assertCount(1, $result[0]->hunks);

        $hunk = $result[0]->hunks[0];
        $this->assertSame(1, $hunk->oldStart);
        $this->assertSame(5, $hunk->oldCount);
        $this->assertSame(1, $hunk->newStart);
        $this->assertSame(6, $hunk->newCount);
    }

    public function testParseNewFile(): void
    {
        $diff = <<<'DIFF'
            diff --git a/src/NewFile.php b/src/NewFile.php
            new file mode 100644
            index 0000000..1234567
            --- /dev/null
            +++ b/src/NewFile.php
            @@ -0,0 +1,5 @@
            +<?php
            +
            +class NewFile
            +{
            +}
            DIFF;

        $result = $this->parser->parse($diff);

        $this->assertCount(1, $result);
        $this->assertSame('src/NewFile.php', $result[0]->path);
        $this->assertSame(ChangedFile::STATUS_ADDED, $result[0]->status);
        $this->assertTrue($result[0]->isAdded());
    }

    public function testParseDeletedFile(): void
    {
        $diff = <<<'DIFF'
            diff --git a/src/OldFile.php b/src/OldFile.php
            deleted file mode 100644
            index 1234567..0000000
            --- a/src/OldFile.php
            +++ /dev/null
            @@ -1,5 +0,0 @@
            -<?php
            -
            -class OldFile
            -{
            -}
            DIFF;

        $result = $this->parser->parse($diff);

        $this->assertCount(1, $result);
        $this->assertSame('src/OldFile.php', $result[0]->path);
        $this->assertSame(ChangedFile::STATUS_DELETED, $result[0]->status);
        $this->assertTrue($result[0]->isDeleted());
    }

    public function testParseRenamedFile(): void
    {
        $diff = <<<'DIFF'
            diff --git a/src/OldName.php b/src/NewName.php
            similarity index 95%
            rename from src/OldName.php
            rename to src/NewName.php
            index 1234567..abcdefg 100644
            --- a/src/OldName.php
            +++ b/src/NewName.php
            @@ -1,3 +1,3 @@
             <?php

            -class OldName {}
            +class NewName {}
            DIFF;

        $result = $this->parser->parse($diff);

        $this->assertCount(1, $result);
        $this->assertSame('src/NewName.php', $result[0]->path);
        $this->assertSame(ChangedFile::STATUS_RENAMED, $result[0]->status);
        $this->assertSame('src/OldName.php', $result[0]->oldPath);
        $this->assertTrue($result[0]->isRenamed());
    }

    public function testParseMultipleFiles(): void
    {
        $diff = <<<'DIFF'
            diff --git a/src/FileA.php b/src/FileA.php
            index 1234567..abcdefg 100644
            --- a/src/FileA.php
            +++ b/src/FileA.php
            @@ -1,3 +1,3 @@
             <?php
            -// old comment
            +// new comment
             class FileA {}
            diff --git a/src/FileB.php b/src/FileB.php
            new file mode 100644
            index 0000000..1234567
            --- /dev/null
            +++ b/src/FileB.php
            @@ -0,0 +1,2 @@
            +<?php
            +class FileB {}
            DIFF;

        $result = $this->parser->parse($diff);

        $this->assertCount(2, $result);
        $this->assertSame('src/FileA.php', $result[0]->path);
        $this->assertSame('src/FileB.php', $result[1]->path);
        $this->assertSame(ChangedFile::STATUS_MODIFIED, $result[0]->status);
        $this->assertSame(ChangedFile::STATUS_ADDED, $result[1]->status);
    }

    public function testParseHunkLines(): void
    {
        $diff = <<<'DIFF'
            diff --git a/src/Example.php b/src/Example.php
            index 1234567..abcdefg 100644
            --- a/src/Example.php
            +++ b/src/Example.php
            @@ -1,4 +1,4 @@
             <?php

            -class Old {}
            +class New {}
             // end
            DIFF;

        $result = $this->parser->parse($diff);
        $lines = $result[0]->hunks[0]->lines;

        $this->assertCount(5, $lines);

        // Context line
        $this->assertSame('context', $lines[0]['type']);
        $this->assertSame('<?php', $lines[0]['content']);
        $this->assertSame(1, $lines[0]['oldLine']);
        $this->assertSame(1, $lines[0]['newLine']);

        // Empty context line
        $this->assertSame('context', $lines[1]['type']);

        // Removed line
        $this->assertSame('remove', $lines[2]['type']);
        $this->assertSame('class Old {}', $lines[2]['content']);
        $this->assertSame(3, $lines[2]['oldLine']);
        $this->assertNull($lines[2]['newLine']);

        // Added line
        $this->assertSame('add', $lines[3]['type']);
        $this->assertSame('class New {}', $lines[3]['content']);
        $this->assertNull($lines[3]['oldLine']);
        $this->assertSame(3, $lines[3]['newLine']);

        // Final context line
        $this->assertSame('context', $lines[4]['type']);
        $this->assertSame('// end', $lines[4]['content']);
    }

    public function testToArray(): void
    {
        $diff = <<<'DIFF'
            diff --git a/src/Example.php b/src/Example.php
            index 1234567..abcdefg 100644
            --- a/src/Example.php
            +++ b/src/Example.php
            @@ -1,3 +1,3 @@
             <?php
            -// old
            +// new
            DIFF;

        $result = $this->parser->parse($diff);
        $array = $result[0]->toArray();

        $this->assertArrayHasKey('path', $array);
        $this->assertArrayHasKey('status', $array);
        $this->assertArrayHasKey('hunks', $array);
        $this->assertSame('src/Example.php', $array['path']);
    }
}
