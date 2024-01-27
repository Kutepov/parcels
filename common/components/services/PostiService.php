<?php namespace common\components\services;

use Carbon\Carbon;
use common\components\services\models\Parcel;
use common\components\services\models\Status;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;

class PostiService extends BaseService implements ValidateTrackNumberInterface, InternationalValidateTrackNumberInterface, AsyncTrackingInterface
{
    public $id = 115;
    private $url = 'https://webservices.posti.fi';

    public function track($trackNumber)
    {
        return $this->trackAsync($trackNumber)->wait();
    }

    public function trackNumberRules(): array
    {
        return [
            'A[A-Z]{1}[0-9]{9}FI',
            'C[A-Z]{1}[0-9]{9}FI',
            'E[A-Z]{1}[0-9]{9}FI',
            'G[A-Z]{1}[0-9]{9}FI',
            'L[A-Z]{1}[0-9]{9}FI',
            'M[A-Z]{1}[0-9]{9}FI',
            'R[A-Z]{1}[0-9]{9}FI',
            'S[A-Z]{1}[0-9]{9}FI',
            'U[A-Z]{1}[0-9]{9}FI',
            'V[A-Z]{1}[0-9]{9}FI',
            '0037[0-9]{16}',
            '0066[0-9]{16}',
            'JJFI[0-9]{17}'
        ];
    }

    public function internationalTrackNumberRules(): array
    {
        return [
            '[A-Z]{2}[0-9]{9}[A-Z]{2}'
        ];
    }

    public function trackAsync($trackNumber): PromiseInterface
    {
        return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://auth-service.posti.fi/api/v1/anonymous_token'), $trackNumber, [], function (ResponseInterface $response) use ($trackNumber) {
            $tokensJson = json_decode($response->getBody()->getContents(), true);

            return $this->sendAsyncRequestWithProxy(new Request('POST', 'https://oma.posti.fi/graphql/v2'), $trackNumber, [
                RequestOptions::HEADERS => [
                    'authorization' => 'Bearer ' . $tokensJson['id_token'],
                    'x-omaposti-roles' => $tokensJson['role_tokens'][0]['token'],
                    'content-type' => 'application/json',
                ],
                RequestOptions::BODY => '{"operationName":"getShipmentView","variables":{"externalCode":"' . $trackNumber . '"},"query":"query getShipmentView($externalCode: String) {\n  shipmentView(externalCode: $externalCode) {\n    id\n    displayId\n    displayName\n    shipmentType\n    userRole\n    parcel {\n      errandCode\n      otherTrackingNumber\n      estimatedDeliveryTime\n      selectedEarliestDeliveryTime\n      selectedLatestDeliveryTime\n      confirmedEarliestDeliveryTime\n      confirmedLatestDeliveryTime\n      lastCollectionDate\n      cashOnDelivery {\n        amount\n        currency\n        __typename\n      }\n      postpayValue {\n        amount\n        currency\n        __typename\n      }\n      packageQuantity {\n        value\n        __typename\n      }\n      createdAt\n      departure {\n        city\n        country\n        postcode\n        __typename\n      }\n      destination {\n        city\n        country\n        postcode\n        __typename\n      }\n      events {\n        city\n        eventCode\n        eventDescription {\n          lang\n          value\n          __typename\n        }\n        reasonCode\n        reasonDescription {\n          lang\n          value\n          __typename\n        }\n        recipientSignature\n        timestamp\n        lockerDetails {\n          lockerCode\n          lockerAddress\n          lockerDescription\n          lockerID\n          lockerRackID\n          __typename\n        }\n        shelfId\n        __typename\n      }\n      modifiedAt\n      parties {\n        consignee {\n          ...party\n          __typename\n        }\n        consignor {\n          ...party\n          __typename\n        }\n        delivery {\n          ...party\n          __typename\n        }\n        payer {\n          ...party\n          __typename\n        }\n        __typename\n      }\n      pickupPoint {\n        availabilityTime\n        city\n        country\n        county\n        latitude\n        locationCode\n        longitude\n        postcode\n        province\n        pupCode\n        state\n        street1\n        street2\n        street3\n        type\n        codPayableOnLocation\n        __typename\n      }\n      references {\n        consignor\n        postiOrderNumber\n        mpsCodGroup\n        __typename\n      }\n      status {\n        code\n        description {\n          lang\n          value\n          __typename\n        }\n        __typename\n      }\n      trackingNumber\n      volume {\n        unit\n        value\n        __typename\n      }\n      weight {\n        unit\n        value\n        __typename\n      }\n      width {\n        ...length\n        __typename\n      }\n      height {\n        ...length\n        __typename\n      }\n      length {\n        ...length\n        __typename\n      }\n      __typename\n    }\n    parcelExtensions {\n      actions {\n        actionType\n        actionUrl\n        __typename\n      }\n      exceptions {\n        exceptionType\n        __typename\n      }\n      powerOfAttorneyStatus\n      widget {\n        hasWidget\n        url\n        __typename\n      }\n      displayOptions {\n        type\n        __typename\n      }\n      deliveryMethod {\n        type\n        __typename\n      }\n      senderOptions {\n        type\n        __typename\n      }\n      digitalDeclaration {\n        status\n        action {\n          type\n          url\n          __typename\n        }\n        __typename\n      }\n      customsClearance {\n        status\n        __typename\n      }\n      general {\n        omaPostiShipmentUrl\n        __typename\n      }\n      __typename\n    }\n    freight {\n      cashOnDelivery {\n        amount\n        currency\n        __typename\n      }\n      selectedEarliestDeliveryTime\n      selectedLatestDeliveryTime\n      confirmedEarliestDeliveryTime\n      confirmedLatestDeliveryTime\n      createdAt\n      departure {\n        city\n        country\n        postcode\n        __typename\n      }\n      destination {\n        city\n        country\n        postcode\n        __typename\n      }\n      events {\n        city\n        eventCode\n        eventDescription {\n          lang\n          value\n          __typename\n        }\n        reasonCode\n        reasonDescription {\n          lang\n          value\n          __typename\n        }\n        recipientSignature\n        timestamp\n        __typename\n      }\n      goodsItems {\n        packageQuantity {\n          unit\n          value\n          __typename\n        }\n        packages {\n          trackingNumber\n          events {\n            city\n            eventCode\n            eventDescription {\n              lang\n              value\n              __typename\n            }\n            reasonCode\n            reasonDescription {\n              lang\n              value\n              __typename\n            }\n            recipientSignature\n            timestamp\n            __typename\n          }\n          __typename\n        }\n        __typename\n      }\n      modifiedAt\n      product {\n        additionalInfo {\n          lang\n          value\n          __typename\n        }\n        code\n        name {\n          lang\n          value\n          __typename\n        }\n        __typename\n      }\n      references {\n        consignor\n        postiOrderNumber\n        mpsCodGroup\n        __typename\n      }\n      status {\n        code\n        description {\n          lang\n          value\n          __typename\n        }\n        __typename\n      }\n      parties {\n        consignee {\n          ...party\n          __typename\n        }\n        consignor {\n          ...party\n          __typename\n        }\n        delivery {\n          ...party\n          __typename\n        }\n        __typename\n      }\n      totalLoadingMeters {\n        unit\n        value\n        __typename\n      }\n      totalPackageQuantity {\n        unit\n        value\n        __typename\n      }\n      totalWeight {\n        unit\n        value\n        __typename\n      }\n      totalFreightWeight {\n        unit\n        value\n        __typename\n      }\n      totalVolume {\n        unit\n        value\n        __typename\n      }\n      urls {\n        longEPodUrl\n        __typename\n      }\n      waybillNumber\n      deliveryDate {\n        ...dateRange\n        __typename\n      }\n      pickupDate {\n        ...dateRange\n        __typename\n      }\n      __typename\n    }\n    freightExtensions {\n      actions {\n        actionType\n        actionUrl\n        __typename\n      }\n      displayOptions {\n        type\n        __typename\n      }\n      deliveryMethod {\n        type\n        __typename\n      }\n      __typename\n    }\n    aftershipParcel {\n      courier\n      courierData {\n        country\n        defaultLanguage\n        iconUrl\n        id\n        name\n        otherLanguages\n        otherName\n        phone\n        url\n        __typename\n      }\n      departure {\n        city\n        country\n        postcode\n        __typename\n      }\n      destination {\n        city\n        country\n        postcode\n        __typename\n      }\n      estimatedDeliveryTime\n      selectedEarliestDeliveryTime\n      selectedLatestDeliveryTime\n      confirmedEarliestDeliveryTime\n      confirmedLatestDeliveryTime\n      events {\n        city\n        country\n        eventAdditionalInfo {\n          lang\n          value\n          __typename\n        }\n        eventCode\n        eventDescription {\n          lang\n          value\n          __typename\n        }\n        eventShortName {\n          lang\n          value\n          __typename\n        }\n        postcode\n        reasonCode\n        timestamp\n        __typename\n      }\n      modifiedAt\n      parties {\n        consignee {\n          ...party\n          __typename\n        }\n        consignor {\n          ...party\n          __typename\n        }\n        __typename\n      }\n      pickupPoint {\n        city\n        country\n        postcode\n        __typename\n      }\n      status {\n        code\n        description {\n          lang\n          value\n          __typename\n        }\n        __typename\n      }\n      trackingNumber\n      __typename\n    }\n    pendingTracking {\n      courier\n      courierData {\n        country\n        defaultLanguage\n        iconUrl\n        id\n        name\n        otherLanguages\n        otherName\n        phone\n        url\n        __typename\n      }\n      isPlusShipment\n      modifiedAt\n      trackingNumber\n      waybillNumber\n      __typename\n    }\n    __typename\n  }\n}\n\nfragment length on TrackingLength {\n  unit\n  value\n  __typename\n}\n\nfragment party on ShipmentViewParty {\n  name1\n  city\n  country\n  postcode\n  state\n  street1\n  street2\n  street3\n  account\n  __typename\n}\n\nfragment dateRange on TrackingDateRange {\n  earliest\n  latest\n  __typename\n}\n"}'
            ]);
        });
    }

    public function parseResponse($response, $trackNumber)
    {
        $request = json_decode($response->getBody()->getContents(), true);

        if (!isset($request['data']['shipmentView'][0]['parcel'])) {
            return false;
        }

        $data = $request['data']['shipmentView'][0]['parcel'];

        $result = new Parcel();
        $result->departureCountryCode = $data['departure']['country'];
        $result->destinationCountryCode = $data['destination']['country'];
        $result->departureAddress = $data['departure']['city'] . ' ' . $data['departure']['postcode'];
        $result->destinationAddress = $data['destination']['city'] . ' ' . $data['destination']['postcode'];

        foreach ($data['events'] as $checkpoint) {
            $date = Carbon::parse($checkpoint['timestamp']);

            $result->statuses[] = new Status([
                'title' => $checkpoint['eventDescription'][0]['value'],
                'date' => $date->timestamp,
                'dateVal' => $date->toDateString(),
                'timeVal' => $date->toTimeString('minute'),
                'location' => $checkpoint['city']
            ]);
        }

        return (!empty($result->statuses)) ? $result : false;
    }
}