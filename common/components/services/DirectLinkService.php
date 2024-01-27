<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use Nesk\Puphpeteer\Puppeteer;
use Nesk\Rialto\Data\JsFunction;
use PHPHtmlParser\Dom;

class DirectLinkService extends BaseService implements ValidateTrackNumberInterface, ManuallySelectedInterface
{
    public $id = 250;

    public function track($trackNumber)
    {
        $puppeteer = new Puppeteer(['executable_path' => '/usr/bin/node']);

        $browser = $puppeteer->launch([
            'args' => ['--proxy-server=' . env('PROXY_ADDRESS')]
        ]);

        $page = $browser->newPage();
        $page->setExtraHTTPHeaders([
            'X-Original-Scheme' => 'https'
        ]);
        $page->goto('http://tracking.directlink.com/?locale=en&itemNumber=' . $trackNumber, ['timeout' => 10000]);
        $page->waitForSelector('.tblEvents,.alert-danger');

        $html = $page->evaluate((new JsFunction)->body('return document.body.innerHTML;'));
        $browser->close();

        $dom = new Dom();
        $dom->loadStr($html);

        if ($table = $dom->find('.tblEvents', 0)) {
            foreach ($table->find('tbody', 0)->find('tr') as $checkpoint) {
                $info = $checkpoint->find('td', 1)->find('div', 0);

                $date = Carbon::parse($info->find('div', 0)->text);

                $statuses[] = new Status([
                    'title' => $info->find('.eventLabel', 0)->text,
                    'location' => $info->find('.locationLabel', 0)->text ?? null,
                    'date' => $date->timestamp,
                    'dateVal' => $date->toDateString(),
                    'timeVal' => $date->toTimeString('minute')
                ]);
            }

            if (isset($statuses)) {
                foreach ([0, 1] as $eq) {
                    if (
                        ($column = $dom->find('.itemDetailsColumnTop', $eq)) &&
                        $header = $column->find('.headerLabel', 0)
                    ) {
                        if ($column->text == 'Destination country') {
                            $destinationAddress = $column->find('.bodyLabel', 0)->text;
                        }
                        else {
                            $departureAddress = $column->find('.bodyLabel', 0)->text;
                        }
                    }
                }


                $result = new Parcel([
                    'departureAddress' => $departureAddress ?? null,
                    'destinationAddress' => $destinationAddress ?? null,
                    'statuses' => $statuses
                ]);
            }
        }

        return $result ?? false;
    }

    public function trackNumberRules(): array
    {
        return [];
    }
}