<?php

declare(strict_types=1);

namespace Bannerstop\Sonar\Tests;

use Bannerstop\Sonar\Scrubber;
use Bannerstop\Sonar\Tests\Support\SecretsFixture;
use PHPUnit\Framework\TestCase;

/**
 * What the Scrubber takes out: by field name, by the shape of a value, and by the value of the application's own secrets.
 */
final class ScrubberTest extends TestCase
{
    private const VALUE = 'invented-field-value-123';

    public function testAFieldNameOnTheListIsScrubbedAndItsKeyStays(): void
    {
        foreach (SecretsFixture::scrubbedNames() as $name) {
            $result = (new Scrubber())->value([$name => self::VALUE, 'other' => 'visible']);

            self::assertSame([$name => '[scrubbed]', 'other' => 'visible'], $result, $name);
        }
    }

    public function testAHarmlessFieldNameKeepsItsValue(): void
    {
        foreach (SecretsFixture::keptNames() as $name) {
            self::assertSame([$name => self::VALUE], (new Scrubber())->value([$name => self::VALUE]), $name);
        }
    }

    public function testNamesTheApplicationAddsMatchAsAWholeOnTopOfTheBuiltInList(): void
    {
        $result = (new Scrubber(['Order-Reference']))->value(['orderReference' => 'A-1', 'orderReferenceCount' => 3, 'password' => 'x']);

        self::assertSame(['orderReference' => '[scrubbed]', 'orderReferenceCount' => 3, 'password' => '[scrubbed]'], $result);
    }

    public function testAValueThatLooksLikeASecretIsRedactedInFreeText(): void
    {
        $scrubber = new Scrubber();
        foreach (SecretsFixture::texts() as $case) {
            self::assertStringNotContainsString($case['leak'], $scrubber->text($case['text'], 2000), $case['label']);
            self::assertStringNotContainsString($case['leak'], $scrubber->value(['harmless' => $case['text']])['harmless'], $case['label']);
        }
    }

    public function testTheRestOfAnOrdinarySentenceSurvives(): void
    {
        $text = 'Order 4711 failed in checkout: status=ok, the keyword search of the monkey shop took 12 ms';

        self::assertSame($text, (new Scrubber())->text($text, 2000));
    }

    public function testAQuotedValueKeepsItsQuotesSoJsonStaysReadable(): void
    {
        $text = '{"db_pass":"invented-json-pass","note":\'ok\',"api_key":\'invented-single-quoted\'}';

        self::assertSame('{"db_pass":"[scrubbed]","note":\'ok\',"api_key":\'[scrubbed]\'}', (new Scrubber())->text($text, 2000));
    }

    public function testAValueWhoseQuoteWasCutOffIsRedactedToTheEndOfTheLine(): void
    {
        self::assertSame('x "password": "[scrubbed]', (new Scrubber())->text('x "password": "invented-cut-off-pass', 2000));
    }

    public function testASecretLikeKeyAfterAHarmlessOneIsFound(): void
    {
        self::assertSame('E_WARNING: sentry_dsn: [scrubbed] next=1', (new Scrubber())->text('E_WARNING: sentry_dsn: invented-dsn-value next=1', 2000));
    }

    public function testTheAuthorizationHeaderLosesItsCredentialButNotTheSentenceAroundIt(): void
    {
        $text = (new Scrubber())->text('Call failed: Authorization: Bearer abcdef123456 for the shop api', 2000);

        self::assertSame('Call failed: Authorization: [scrubbed] for the shop api', $text);
    }

    public function testASecretIsRedactedBeforeTheTextIsCut(): void
    {
        $text = str_repeat('x', 490).' password=invented-cut-pass-0123456789';

        self::assertStringNotContainsString('invented-cut', (new Scrubber())->text($text, 500));
        self::assertStringNotContainsString('invented-cut', (new Scrubber())->value([$text])[0]);
    }

    public function testAKeyThatIsASecretIsRedacted(): void
    {
        $result = (new Scrubber())->value(['ghp_INVENTED0009' => true]);

        self::assertSame(['[scrubbed]' => true], $result);
    }

    public function testTheValueOfASecretEnvironmentVariableIsRedactedWhereverItAppears(): void
    {
        $secrets = Scrubber::secretValues([['DB_HOST' => 'invented-host-value', 'MY_SECRET' => 'invented-unexpected-secret', 'SHORT_TOKEN' => 'short']]);
        $scrubber = new Scrubber([], $secrets);

        $result = $scrubber->value(['note' => 'saw invented-unexpected-secret in a line', 'host' => 'invented-host-value', 'short' => 'short']);

        self::assertSame(['note' => 'saw [scrubbed] in a line', 'host' => 'invented-host-value', 'short' => 'short'], $result);
        self::assertSame('x [scrubbed]', $scrubber->text('x invented-unexpected-secret', 100));
    }

    public function testTheLongestEnvironmentSecretGoesFirst(): void
    {
        $secrets = Scrubber::secretValues([['A_SECRET' => 'invented-secret', 'B_SECRET' => 'invented-secret-with-a-tail']]);

        self::assertSame('[scrubbed]', (new Scrubber([], $secrets))->text('invented-secret-with-a-tail', 100));
    }

    public function testEnvironmentValuesAreCollectedByNameFromEveryBagAndIgnoreOtherTypes(): void
    {
        $secrets = Scrubber::secretValues([
            ['APP_KEY' => 'invented-from-first-bag', 'HTTP_COOKIE' => 'sid=invented-cookie', 'THE_LIST_TOKEN' => ['invented-array-value'], 7 => 'invented-int-key-secret'],
            ['DB_PASS' => 'invented-from-second-bag', 'APP_NAME' => 'invented-not-secret', 'PWD' => '/invented/working/directory', 'OLDPWD' => '/invented/old/directory'],
        ]);

        sort($secrets);
        self::assertSame(['invented-from-first-bag', 'invented-from-second-bag', 'sid%3Dinvented-cookie', 'sid=invented-cookie'], $secrets);
    }

    public function testAnEnvironmentSecretIsAlsoRedactedInItsUrlEncodedForm(): void
    {
        $secrets = Scrubber::secretValues([['MAIL_PASSWORD' => 'invented p@ss/word']]);

        $text = (new Scrubber([], $secrets))->text('GET /?p=invented%20p%40ss%2Fword and invented+p%40ss%2Fword', 200);

        self::assertStringNotContainsString('%40ss', $text);
    }
}
