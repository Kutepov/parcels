<?php

namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\DomCrawler\Crawler;

class BruneiPostService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    
    public $id = 100;
    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'http://www.post.gov.bn/SitePages/Track%20Items.aspx'), $trackNumber, [
            RequestOptions::COOKIES => $jar = new CookieJar(),
        ], function (ResponseInterface $response) use ($trackNumber, $jar) {
            $content = $response->getBody()->getContents();
            preg_match('/__REQUESTDIGEST.*?value="(.*?)"/is', $content, $__REQUESTDIGEST);
            preg_match('/__VIEWSTATE.*?value="(.*?)"/is', $content, $__VIEWSTATE);
            preg_match('/__VIEWSTATEGENERATOR.*?value="(.*?)"/is', $content, $__VIEWSTATEGENERATOR);
            preg_match('/__EVENTVALIDATION.*?value="(.*?)"/is', $content, $__EVENTVALIDATION);

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'http://www.post.gov.bn/SitePages/Track%20Items.aspx'), $trackNumber, [
                RequestOptions::COOKIES => $jar,
                RequestOptions::TIMEOUT => 30,
                RequestOptions::CONNECT_TIMEOUT => 30,
                RequestOptions::HEADERS => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Cache-Control' => 'max-age=0',
                    'Connection' => 'keep-alive',
                    'Content-Type' => 'application/x-www-form-urlencoded',
                    'Host' => 'www.post.gov.bn',
                    'Origin' => 'http://www.post.gov.bn',
                    'Referer' => 'http://www.post.gov.bn/SitePages/Track%20Items.aspx',
                    'Upgrade-Insecure-Requests' => '1',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/96.0.4664.110 Safari/537.36',
                ],
                RequestOptions::FORM_PARAMS => [
                    '_wpcmWpid' => '',
                    'wpcmVal' => '',
                    'MSOWebPartPage_PostbackSource' => '',
                    'MSOTlPn_SelectedWpId' => '',
                    'MSOTlPn_View' => '0',
                    'MSOTlPn_ShowSettings' => 'False',
                    'MSOGallery_SelectedLibrary' => '',
                    'MSOGallery_FilterString' => '',
                    'MSOTlPn_Button' => 'none',
                    '_wikiPageMode' => '',
                    '__EVENTTARGET' => '',
                    '__EVENTARGUMENT' => '',
                    '__REQUESTDIGEST' => $__REQUESTDIGEST[1],
                    '_wikiPageCommand' => '',
                    'SPPageStateContext_PreviousAuthoringVersion' => '7',
                    'MSOSPWebPartManager_DisplayModeName' => 'Browse',
                    'MSOSPWebPartManager_ExitingDesignMode' => 'false',
                    'MSOWebPartPage_Shared' => '',
                    'MSOLayout_LayoutChanges' => '',
                    'MSOLayout_InDesignMode' => '',
                    '_wpSelected' => '',
                    '_wzSelected' => '',
                    'MSOSPWebPartManager_OldDisplayModeName' => 'Browse',
                    'MSOSPWebPartManager_StartWebPartEditingName' => 'false',
                    'MSOSPWebPartManager_EndWebPartEditing' => 'false',
                    '_maintainWorkspaceScrollPosition' => '0',
                    'ctl00_PlaceHolderLeftNavBar_ctl02_NavResizerWidth' => '0',
                    'ctl00_PlaceHolderLeftNavBar_ctl02_NavResizerHeight' => '0',
                    'ctl00_PlaceHolderLeftNavBar_ctl02_TreeViewRememberScrollScrollTop' => '0',
                    'ctl00_PlaceHolderLeftNavBar_ctl02_TreeViewRememberScrollScrollLeft' => '0',
                    'ctl00_PlaceHolderLeftNavBar_ctl02_WebTreeView_ExpandState' => 'nnnnnncnnncnnnnncnnncnnnnnnnnnnnnnnnncnccnnnnnnncncnncnc',
                    'ctl00_PlaceHolderLeftNavBar_ctl02_WebTreeView_SelectedNode' => 'ctl00_PlaceHolderLeftNavBar_ctl02_WebTreeViewt41',
                    'ctl00_PlaceHolderLeftNavBar_ctl02_WebTreeView_PopulateLog' => '',
                    '__VIEWSTATE' => $__VIEWSTATE[1],
                    '__VIEWSTATEGENERATOR' => $__VIEWSTATEGENERATOR[1],
                    '__SCROLLPOSITIONX' => '0',
                    '__SCROLLPOSITIONY' => '0',
                    '__EVENTVALIDATION' => $__EVENTVALIDATION[1],
                    'ctl00$ctl54' => '',
                    'ctl00$PlaceHolderMain$wikiPageNameEditTextBox' => 'Track Items',
                    'ctl00$ctl40$g_3f4e1e70_6cb0_4c6b_b9a9_d8c4ea4bf2ec$txtTrackingNumber' => $trackNumber,
                    'ctl00$ctl40$g_3f4e1e70_6cb0_4c6b_b9a9_d8c4ea4bf2ec$btnGetTrackingDetails' => 'Submit',
                ]
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $dom = new Crawler($response->getBody()->getContents());

        if ($dom->filterXPath('//span[@id="ctl00_ctl40_g_3f4e1e70_6cb0_4c6b_b9a9_d8c4ea4bf2ec_lblInvalidMsg"]')->count()) {
            return false;
        }

        $result = new Parcel();

        $dom->filterXPath('//table[@id="ctl00_ctl40_g_3f4e1e70_6cb0_4c6b_b9a9_d8c4ea4bf2ec_grdTrackingDetails"]//tr[@class="rows"]')->each(function (Crawler $node) use (&$result) {
            $date = Carbon::parse($node->filterXPath('//td[1]')->text() . ' ' . $node->filterXPath('//td[2]')->text());
            $result->statuses[] = new Status([
                'title' => $node->filterXPath('//td[1]')->text(),
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
            ]);
        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}BN',
            'C[A-Z]{1}[0-9]{9}BN',
            'E[A-Z]{1}[0-9]{9}BN',
            'L[A-Z]{1}[0-9]{9}BN',
            'R[A-Z]{1}[0-9]{9}BN',
            'S[A-Z]{1}[0-9]{9}BN',
            'U[A-Z]{1}[0-9]{9}BN',
            'V[A-Z]{1}[0-9]{9}BN'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }
}