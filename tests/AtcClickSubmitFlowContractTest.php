<?php
/**
 * Contract tests for camp/course variable add-to-cart JS in elementor-widgets.php:
 * stash dedupe on ATC, pre-click stash wait, single native form POST, timeout copy.
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/tests/bootstrap.php';

class AtcClickSubmitFlowContractTest extends TestCase
{
    private function elementorWidgetsSource(): string
    {
        return file_get_contents(dirname(__DIR__) . '/includes/elementor-widgets.php');
    }

    public function testEnsurePlayerIndexSkipsStashOnAtcPath(): void
    {
        $php = $this->elementorWidgetsSource();
        $this->assertStringContainsString('intersoccerApplyPlayerChoice(idx, { skipStash: true })', $php);
    }

    public function testPlayerStashDedupeTracksLastSavedAndInFlightSignatures(): void
    {
        $php = $this->elementorWidgetsSource();
        $this->assertStringContainsString('intersoccerPvLastSavedPlayerStashSig', $php);
        $this->assertStringContainsString('intersoccerPvInFlightPlayerStashSig', $php);
        $this->assertStringContainsString('intersoccerPvPlayerStashSignature', $php);
        $this->assertStringContainsString('sig === intersoccerPvLastSavedPlayerStashSig', $php);
    }

    public function testSubmitWaitUsesPreClickBaselineNotAllPending(): void
    {
        $php = $this->elementorWidgetsSource();
        $this->assertStringContainsString('intersoccerPvSnapshotStashPendingBaseline', $php);
        $this->assertStringContainsString('intersoccerPvConsumeStashPendingBaseline', $php);
        $this->assertStringContainsString('intersoccerPvHasPendingStashForSubmitWait', $php);
        $this->assertStringContainsString('intersoccerPvWaitForPreClickStashAjax', $php);
        $this->assertStringContainsString('intersoccerPvStashPendingBeforeAtc', $php);
        $this->assertStringContainsString('intersoccerPvIsStashSubmitWaitInProgress', $php);
        $this->assertStringContainsString('intersoccerPvSetStashSubmitWaitInProgress', $php);
        $this->assertStringContainsString('intersoccerPvSetStashSubmitWaitInProgress($stashWaitForm, true)', $php);
        $this->assertStringContainsString('intersoccerPvSetStashSubmitWaitInProgress($stashWaitForm, false)', $php);
        $this->assertStringContainsString('intersoccerPvPreStashWaitDisabled', $php);
        $this->assertStringContainsString('function intersoccerPvScheduleNativeProductFormSubmit($productForm, e, afterNativeSubmit)', $php);
        $this->assertStringContainsString('intersoccerPvScheduleNativeProductFormSubmit($productForm, null, function () {', $php);
        $this->assertStringNotContainsString('intersoccerPvResubmitAfterStashWait', $php);
        $this->assertStringNotContainsString('$stashWaitForm.trigger(\'submit\')', $php);
        $this->assertStringContainsString('function intersoccerPvRestoreStashSubmitWaitButtons', $php);
        $this->assertStringContainsString('var stashWaitResubmitScheduled = false', $php);
        $this->assertStringContainsString('if (!stashWaitResubmitScheduled)', $php);
    }

    public function testResubmitAfterStashWaitRestoresButtonsBeforeValidation(): void
    {
        $php = $this->elementorWidgetsSource();
        if (!preg_match(
            '/function intersoccerPvResubmitCampCourseFormAfterStashWait\(\$productForm\) \{(.*?)\n            \}/s',
            $php,
            $m
        )) {
            $this->fail('intersoccerPvResubmitCampCourseFormAfterStashWait body not found');
        }
        $body = $m[1];
        $restorePos = strpos($body, 'intersoccerPvRestoreStashSubmitWaitButtons');
        $validatePos = strpos($body, 'intersoccerPvValidateCampCourseProductForm');
        $this->assertNotFalse($restorePos, 'restore helper call missing from resubmit function');
        $this->assertNotFalse($validatePos, 'validate call missing from resubmit function');
        $this->assertLessThan($validatePos, $restorePos, 'restore must run before validation in resubmit path');
        $this->assertStringContainsString('return true', $body);
        $this->assertStringContainsString('return false', $body);
    }

    public function testNativeSubmitUsesPreventDefaultAndHtmlFormElementSubmit(): void
    {
        $php = $this->elementorWidgetsSource();
        $this->assertStringContainsString('function intersoccerPvScheduleNativeProductFormSubmit', $php);
        $this->assertStringContainsString('e.preventDefault()', $php);
        $this->assertStringContainsString('HTMLFormElement.prototype.submit.call(formElement)', $php);
        $this->assertStringContainsString('intersoccerPvResubmitCampCourseFormAfterStashWait', $php);
        $this->assertStringContainsString('intersoccerPvScheduleNativeProductFormSubmit($productForm, null, function () {', $php);
        $this->assertStringContainsString('Fallback form submission failed', $php);
        if (!preg_match(
            '/function intersoccerPvScheduleNativeProductFormSubmit\(\$productForm, e, afterNativeSubmit\) \{(.*?)\n            \}/s',
            $php,
            $m
        )) {
            $this->fail('intersoccerPvScheduleNativeProductFormSubmit body not found');
        }
        $scheduleBody = $m[1];
        $finallyPos = strpos($scheduleBody, '} finally {');
        $afterNativePos = strpos($scheduleBody, 'afterNativeSubmit');
        $this->assertNotFalse($finallyPos, 'finally block missing from native submit scheduler');
        $this->assertNotFalse($afterNativePos, 'afterNativeSubmit missing from native submit scheduler');
        $this->assertGreaterThan($finallyPos, $afterNativePos, 'afterNativeSubmit must run inside finally');
    }

    public function testStashWaitTimeoutStringStillLocalizedInWidgetScript(): void
    {
        $php = $this->elementorWidgetsSource();
        $this->assertStringContainsString(
            'Booking data is still saving. Please wait a moment, then try Add to cart again.',
            $php
        );
        $this->assertStringContainsString('stash_wait_timeout', $php);
        $this->assertStringContainsString('intersoccerPvShowStashWaitTimeoutNotice', $php);
    }

    public function testProductEnhancerJsIsNotRegisteredOrEnqueued(): void
    {
        $main = file_get_contents(dirname(__DIR__) . '/intersoccer-product-variations.php');
        $this->assertStringNotContainsString('product-enhancer.js', $main);
        foreach (glob(dirname(__DIR__) . '/includes/**/*.php') ?: [] as $path) {
            $contents = file_get_contents($path);
            if (strpos($contents, 'product-enhancer') !== false) {
                $this->assertStringNotContainsString('wp_enqueue_script', $contents, $path);
                $this->assertStringNotContainsString('wp_register_script', $contents, $path);
            }
        }
    }
}
