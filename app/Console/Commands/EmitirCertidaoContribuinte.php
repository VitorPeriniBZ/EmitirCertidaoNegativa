<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Panther\Client;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverSelect;
use Facebook\WebDriver\Exception\NoSuchWindowException;
use Facebook\WebDriver\WebDriverExpectedCondition;

class EmitirCertidaoContribuinte extends Command
{
    protected $signature = 'emitir {cnpj}';
    protected $description = 'Emite certidao negativa de contribuinte e salva como PDF';

    public function handle()
    {
        $cnpj = $this->argument('cnpj');

        $downloadPath = storage_path('\certidoes');
        if (!is_dir($downloadPath)) {
            mkdir($downloadPath, 0777, true);
        }

        $client = Client::createChromeClient(
            null,
            [
                '--start-maximized'
            ],
            [
            'prefs' => [
            'download.default_directory' => $downloadPath,
            'download.prompt_for_download' => false,
            'plugins.always_open_pdf_externally' => true,],
            ]
        );

        try {
            $crawler = $client->request('GET', 'https://e-gov.betha.com.br/cdweb/03114-496/main.faces');

            $client->waitFor('select[id="mainForm:estados"]', 10);
            $selectEstado = new WebDriverSelect($client->getWebDriver()->findElement(WebDriverBy::id('mainForm:estados')));
            $selectEstado->selectByVisibleText('SC - Santa Catarina');

            $client->getWebDriver()->wait(10)->until(function () use ($client) {
                $el = $client->getWebDriver()->findElement(WebDriverBy::id('mainForm:municipios'));
                return !$el->getAttribute('disabled');
            });

            $selectCidade = new WebDriverSelect($client->getWebDriver()->findElement(WebDriverBy::id('mainForm:municipios')));
            $selectCidade->selectByVisibleText('Prefeitura Municipal de Navegantes');
            $client->getWebDriver()->findElement(WebDriverBy::id('mainForm:selecionar'))->click();

            // Troca de aba
            $windowHandles = $client->getWebDriver()->getWindowHandles();
            $client->getWebDriver()->switchTo()->window(end($windowHandles));

            // Aguarda link da certidao
            $link = $client->getWebDriver()->wait(10)->until(
                WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('[href="rel_cndcontribuinte.faces"]'))
            );
            $link->click();

            $windowHandles = $client->getWebDriver()->getWindowHandles();
            $client->getWebDriver()->switchTo()->window(end($windowHandles));

            // Clica em "CNPJ"
            $client->getWebDriver()->wait(10)->until(
                WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::cssSelector('a.cnpj.btModo'))
            )->click();

            // Aguarda CNPJ input
            $client->getWebDriver()->wait(10)->until(
                WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::id('mainForm:cnpj'))
            );

            $client->getWebDriver()->findElement(WebDriverBy::id('mainForm:cnpj'))->sendKeys($cnpj);
            $client->getWebDriver()->findElement(WebDriverBy::id('mainForm:btCnpj'))->click();

            // Aguarda botão "Emitir"
            $client->getWebDriver()->wait(15)->until(
                WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::cssSelector('img[alt="Emitir"][title*="CND"]'))
            )->click();

            // Aguarda modal abrir e iframe aparecer
            $iframe = $client->getWebDriver()->wait(15)->until(
                WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::cssSelector('iframe.fancybox-iframe'))
            );
            $client->getWebDriver()->switchTo()->frame($iframe);

            // Aguarda o botão de download
            $client->getWebDriver()->wait(10)->until(
                WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::id('download'))
            )->click();
            sleep(3);

        } catch (NoSuchWindowException $e) {
            $this->error("Erro: Janela foi fechada. " . $e->getMessage());
        } catch (\Exception $e) {
            $this->error("Ocorreu um erro: " . $e->getMessage());
        }
    }
}
