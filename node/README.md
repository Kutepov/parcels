Запрос из парсеров должен иметь следующую структуру:

```
return $this->sendAsyncRequestWithProxy(new Request('POST', '{NODE SERVER}'), $trackNumber,
    [
        RequestOptions::HEADERS => [
            //Заголовки передаются стандартным способом
        ],
        RequestOptions::FORM_PARAMS => [
            //Два обязательных параметра. Остальные добавятся в тело запроса уже к сервису, если method указан POST
            'requestUrl' => 'https://api2.apc-pli.com/api/tracking/'.$trackNumber,
            'method' => 'POST',
            //Если нужно ожидать какой-либо селектор, то передаем в Xpath
            'waitForSelector' => '//div[@class="test"]',
        ]
    ]);
}
```

**Даже если сервис возвращает чистый json, puppeteer всё равно его оборачивает в ```html>body>pre```**
