'use strict';
process.title = 'node_server';
const express = require('express');
const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth')
const qs = require('qs');
const bodyParser = require('body-parser');

const puppeteerConfig = {
    headless: true,
    args: [
        "--disable-dev-shm-usage",
        "--disable-setuid-sandbox",
        "--no-sandbox",
        "--window-size=1920,1080",
        '--disable-web-security',
    ],
    defaultViewport: {
        width:1920,
        height:1080
    }
};
const useragentPuppeteer = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/97.0.4692.99 Safari/537.36';

const port = 3000;
const host = '0.0.0.0';
const app = express();

app.use(bodyParser.urlencoded({ extended: true }));
puppeteer.use(StealthPlugin())

app.get('/cainiao', (req, res) => {
    let url = req.query.url;
    let isBody = req.query.body;

    if (url !== undefined) {
        (async function main() {
            try {
                const browser = await puppeteer.launch(puppeteerConfig);
                const [page] = await browser.pages();
                await page.setUserAgent(useragentPuppeteer);
                await page.goto(url);

                try {
                    await page.waitForXPath('//*[@id="nc_1_n1z"]');
                } catch (e) {
                    res.statusCode = 400;
                    res.send('Not find slider');
                    return;
                }

                const slider = await page.$('#nc_1_n1z');
                const bounding_box = await slider.boundingBox();
                await page.mouse.move(bounding_box.x + bounding_box.width / 2, bounding_box.y + bounding_box.height / 2);
                await page.mouse.down();
                await page.mouse.move(bounding_box.x + 279, 0);
                await page.mouse.up();

                try {
                    await page.waitForXPath('//*[@id="waybill_list_val_box"]');
                } catch (e) {
                    res.statusCode = 400;
                    res.send('Not find track data');
                    return;
                }

                if (isBody) {
                    const jsResult = await page.evaluate(() => {return document.body.innerHTML;})
                    res.send(jsResult);
                } else {
                    const cookies = await page.cookies()
                    res.send(cookies);
                }
                await browser.close();
            } catch (err) {
                res.statusCode = 400;
                res.send(err);
            }
        })();
    }
    else {
        res.send('Url is required');
    }

});

app.post('/', (req, res) => {
    let body = req.body;
    let url = body.requestUrl;
    let method = body.method;
    let getCookies = body.getCookies;
    let waitForSelector = body.waitForSelector;
    let headers = req.headers;

    body = clearBody(body);
    headers = clearHeaders(headers);

    if (url !== undefined) {
        (async function main() {
            try {
                const browser = await puppeteer.launch(puppeteerConfig);
                const [page] = await browser.pages();

                await page.setUserAgent(useragentPuppeteer);
                if (method === 'POST') {
                    await page.setRequestInterception(true);
                    page.on('request', interceptedRequest => {
                        var data = {
                            'method': 'POST',
                            'postData': qs.stringify(body)
                        };
                        interceptedRequest.continue(data);
                    });
                }

                await page.setExtraHTTPHeaders(headers);
                await page.goto(url);

                try {
                    if (waitForSelector) {
                        await page.waitForXPath(waitForSelector);
                    }
                } catch (e) {
                    res.statusCode = 400;
                    res.send('Selector not found');
                    return;
                }

                if (getCookies) {
                    const cookies = await page.cookies()
                    res.send(cookies);
                } else {
                    const jsResult = await page.evaluate(() => {return document.body.innerHTML;})
                    res.send(jsResult);
                }
                await browser.close();
            } catch (err) {
                console.log(err);
                res.send(err);
            }
        })();
    }
    else {
        res.send('Url is required');
    }
});

function clearBody(body)
{
    delete body['method'];
    delete body['requestUrl'];
    delete body['waitForSelector'];
    return body;
}

function clearHeaders(headers)
{
    delete headers['host'];
    delete headers['content-length'];
    return headers;
}

app.listen(port, host);