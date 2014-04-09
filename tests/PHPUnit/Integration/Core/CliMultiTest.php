<?php
/**
 * Piwik - Open source web analytics
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

use \Piwik\Version;
use \Piwik\CliMulti;

/**
 * Class Core_CliMultiTest
 *
 * @group Core
 */
class Core_CliMultiTest extends IntegrationTestCase
{
    /**
     * @var \Piwik\CliMulti
     */
    private $cliMulti;

    /**
     * @var string
     */
    private $authToken = '';

    /**
     * @var string[]
     */
    private $urls = array();

    /**
     * @var string[]
     */
    private $responses = array();

    public function setUp()
    {
        parent::setUp();

        $this->cliMulti  = new CliMulti();
        $this->authToken = Fixture::getTokenAuth();

        $this->urls = array(
            'getAnswerToLife' => $this->completeUrl('?module=API&method=ExampleAPI.getAnswerToLife&format=JSON'),
            'getPiwikVersion' => $this->completeUrl('?module=API&method=API.getPiwikVersion&format=JSON'),
        );

        $this->responses = array(
            'getAnswerToLife' => '{"value":42}',
            'getPiwikVersion' => '{"value":"' . Version::VERSION . '"}'
        );

        \Piwik\Common::$isCliMode = true;
    }

    public function test_request_shouldNotFailAndReturnNoResponse_IfNoUrlsAreGiven()
    {
        $response = $this->cliMulti->request(array());

        $this->assertEquals(array(), $response);
    }

    /**
     * @expectedException \Exception
     * @expectedExceptionMessage array
     */
    public function test_request_shouldFail_IfUrlsIsNotAnArray()
    {
        $this->cliMulti->request('');
    }

    public function test_request_shouldReturnResultAsArray_IfOnlyOneUrlIsGiven()
    {
        $urls = $this->buildUrls('getAnswerToLife');

        $this->assertRequestReturnsValidResponses($urls, array('getAnswerToLife'));
    }

    public function test_request_shouldRunAsync()
    {
        $this->assertTrue($this->cliMulti->supportsAsync);
    }

    public function test_request_shouldRequestAllUrls_IfMultipleUrlsAreGiven()
    {
        $urls = $this->buildUrls('getPiwikVersion', 'getAnswerToLife');

        $this->assertRequestReturnsValidResponses($urls, array('getPiwikVersion', 'getAnswerToLife'));
    }

    public function test_request_shouldReturnSameAmountOfResponses_IfSameUrlAppearsMultipleTimes()
    {
        $urls = $this->buildUrls('getAnswerToLife', 'getAnswerToLife');

        $this->assertRequestReturnsValidResponses($urls, array('getAnswerToLife', 'getAnswerToLife'));
    }

    public function test_request_shouldCleanupAllTempFiles_OnceAllRequestsAreFinished()
    {
        $filesBefore = $this->getFilesInTmpFolder();

        $this->cliMulti->request($this->buildUrls('getAnswerToLife', 'getAnswerToLife'));

        $filesAfter = $this->getFilesInTmpFolder();

        $this->assertSame($filesAfter, $filesBefore, "Diff is :" . implode(", ", array_diff($filesAfter, $filesBefore)));
        $this->assertGreaterThan(1, $filesAfter);
    }

    public function test_request_shouldWorkInCaseItDoesNotRunFromCli()
    {
        $urls = $this->buildUrls('getAnswerToLife', 'getAnswerToLife');

        \Piwik\Common::$isCliMode = false;
        $this->assertRequestReturnsValidResponses($urls, array('getAnswerToLife', 'getAnswerToLife'));
    }

    /**
     * This is a known issue, we do not get a content in case Piwik ends with an exit or redirect, but we have to make
     * sure we detect the request has finished though
     */
    public function test_request_shouldDetectFinishOfRequest_IfNoParamsAreGiven()
    {
        $response = $this->cliMulti->request(array($this->completeUrl('')));
        $this->assertStringStartsWith('Error: no website was found', $response[0]);
    }

    public function test_request_shouldBeAbleToRenderARegularPageInPiwik()
    {
        Fixture::createWebsite('2014-01-01 00:00:00');

        $urls = array($this->completeUrl('/?module=Widgetize&idSite=1&period=day&date=today'));

        $response = $this->cliMulti->request($urls);

        $message = "Response was: " . substr( implode("\n\n", $response), 0, 4000);
        $this->assertTrue(false !== strpos($response[0], '<meta name="generator" content="Piwik - Open Source Web Analytics"/>'), $message);
        $this->assertTrue(false !== strpos($response[0], 'Widgetize the full dashboard'). $message);
    }

    public function test_shouldFallback_IfAsyncIsNotSupported()
    {
        $this->cliMulti->supportsAsync = false;

        $urls = $this->buildUrls('getPiwikVersion', 'getAnswerToLife', 'getPiwikVersion');

        $this->assertRequestReturnsValidResponses($urls, array('getPiwikVersion', 'getAnswerToLife', 'getPiwikVersion'));
    }

    public function test_cleanupNotRemovedFiles_shouldOnlyRemoveFiles_IfTheyAreOlderThanOneWeek()
    {
        $timeOneWeekAgo = strtotime('-1 week');

        // make sure -1 week returns a timestamp one week ago
        $this->assertGreaterThan(604797, time() - $timeOneWeekAgo);
        $this->assertLessThan(604803, time() - $timeOneWeekAgo);

        $tmpDir = CliMulti::getTmpPath() . '/';
        touch($tmpDir . 'now.pid');
        touch($tmpDir . 'now.output');
        touch($tmpDir . 'toberemoved.pid', $timeOneWeekAgo - 10);
        touch($tmpDir . 'toberemoved.output', $timeOneWeekAgo - 10);
        touch($tmpDir . 'toBeNotRemoved.pid', $timeOneWeekAgo + 10);
        touch($tmpDir . 'toBeNotRemoved.output', $timeOneWeekAgo + 10);

        CliMulti::cleanupNotRemovedFiles();

        $this->assertFileExists($tmpDir . 'now.pid');
        $this->assertFileExists($tmpDir . 'now.output');
        $this->assertFileExists($tmpDir . 'toBeNotRemoved.output');
        $this->assertFileExists($tmpDir . 'toBeNotRemoved.output');

        $this->assertFileNotExists($tmpDir . 'toberemoved.output');
        $this->assertFileNotExists($tmpDir . 'toberemoved.output');
    }

    private function assertRequestReturnsValidResponses($urls, $expectedResponseIds)
    {
        $actualResponse = $this->cliMulti->request($urls);

        $this->assertInternalType('array', $actualResponse);
        $this->assertCount(count($expectedResponseIds), $actualResponse);

        $expected = array();
        foreach ($expectedResponseIds as $expectedResponseId) {
            $expected[] = $this->responses[$expectedResponseId];
        }

        $this->assertEquals($expected, $actualResponse);
    }

    private function buildUrls()
    {
        $urls = array();

        foreach (func_get_args() as $urlId) {
            $urls[] = $this->urls[$urlId];
        }

        return $urls;
    }

    private function completeUrl($query)
    {
        $host = Fixture::getRootUrl();

        if (false === strpos($query, '?')) {
            $query .= '?';
        } else {
            $query .= '&';
        }

        return $host . 'tests/PHPUnit/proxy/index.php' . $query . 'testmode=1&token_auth=' . $this->authToken;
    }

    private function getFilesInTmpFolder()
    {
        $dir = PIWIK_INCLUDE_PATH . '/tmp';

        $files = \_glob($dir . "/*", null);
        $subFiles = \_glob($dir . "/*/*", null);

        $files = array_merge($files, $subFiles);

        return $files;
    }
}