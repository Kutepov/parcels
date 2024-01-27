<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use Symfony\Component\DomCrawler\Crawler;

class KoreaPostService extends BaseService implements ValidateTrackNumberInterface, AsyncTrackingInterface, CountryRestrictionInterface
{
    public $id = 448;

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('GET', 'https://service.epost.go.kr/trace.RetrieveEmsRigiTraceList.comm?POST_CODE=' . $trackNumber . '&displayHeader=N'), $trackNumber);
    }

    public function trackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}' // ES318211652KR
        ];
    }

    private function getTableContent(string $contents): ?string
    {
        $data = explode('<div class="h4_wrap ma_t_10">
					<div class="title_wrap">
						<h4>배송 진행상황</h4>
					</div>
				</div>', $contents);
        if (count($data) === 1) {
            return null;
        }
        return explode('</table>',$data[1])[0] . '</table>';
    }

    public function parseResponse($response, $trackNumber)
    {
        $contents = $response->getBody()->getContents();
        $table = $this->getTableContent($contents);
        if (!$table) {
            return false;
        }

        $dom = new Crawler($table);

        if (!$dom->filterXPath('//tr')->count()) {
            return false;
        }

        $result = new Parcel();

        $dom->filterXPath('//tr')->each(function (Crawler $checkpoint) use (&$result) {
            if (!$checkpoint->filterXPath('//td')->count()) {
                return;
            }
            $dateTime = Carbon::parse(str_replace('.', '-', $checkpoint->filterXPath('//td[1]')->text()));

            $result->statuses[] = new Status([
                'title' => $checkpoint->filterXPath('//td[2]')->text(),
                'location' => $checkpoint->filterXPath('//td[3]')->text(),
                'date' => $dateTime->timestamp,
                'dateVal' => $dateTime->toDateString(),
                'timeVal' => $dateTime->toTimeString('minute'),
            ]);
        });

        return (!empty($result->statuses)) ? $result : false;
    }

    public function restrictCountries()
    {
        return [
            'us',
            'ca',
            'mx',
            'ru',
            'kr'
        ];
    }
}