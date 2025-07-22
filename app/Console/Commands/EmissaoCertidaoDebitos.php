<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Panther\Client;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\Exception\NoSuchWindowException;
use Facebook\WebDriver\WebDriverExpectedCondition;

class EmitirCertidaoContribuinte extends Command
{
    protected $signature = 'emitir {documento} {--espolio}';
    protected $description = 'Emite certidão negativa da SEFAZ GO e salva como PDF';

    public function handle()
    {
        $documento = preg_replace('/\D/', '', $this->argument('documento'));
        $espolio = $this->option('espolio');

        $tipo = match(strlen($documento)) {
            11 => 'CPF',
            14 => 'CNPJ',
            default => null,
        };

        if (!$tipo) {
            $this->error("Número de documento inválido.");
            return;
        }

        $downloadPath = storage_path('certidoes');
        if (!is_dir($downloadPath)) {
            mkdir($downloadPath, 0777, true);
        }

        $client = Client::createChromeClient(
            null,
            ['--start-maximized'],
            [
                'prefs' => [
                    'download.default_directory' => $downloadPath,
                    'download.prompt_for_download' => false,
                    'plugins.always_open_pdf_externally' => true,
                ]
            ]
        );

        try {
            $crawler = $client->request('GET', 'https://www.sefaz.go.gov.br/certidao/emissao/');
            $webDriver = $client->getWebDriver();

            // Aguarda o campo CPF/CNPJ
            $webDriver->wait(10)->until(
                WebDriverExpectedCondition::presenceOfElementLocated(WebDriverBy::id('Certidao.NumeroDocumentoCPF'))
            );

            // Preenche tipo de documento
            if ($tipo === 'CPF') {
                $webDriver->findElement(WebDriverBy::id('Certidao.TipoDocumentoCPF'))->click();
                $webDriver->findElement(WebDriverBy::id('Certidao.NumeroDocumentoCPF'))->clear();
                $webDriver->findElement(WebDriverBy::id('Certidao.NumeroDocumentoCPF'))->sendKeys($documento);
            } else {
                $webDriver->findElement(WebDriverBy::id('Certidao.TipoDocumentoCNPJ'))->click();
                $webDriver->wait(2)->until(
                    WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::id('Certidao.NumeroDocumentoCNPJ'))
                );
                $webDriver->findElement(WebDriverBy::id('Certidao.NumeroDocumentoCNPJ'))->clear();
                $webDriver->findElement(WebDriverBy::id('Certidao.NumeroDocumentoCNPJ'))->sendKeys($documento);
            }

            // Marca espólio
            $webDriver->findElement(WebDriverBy::id($espolio ? 'Certidao.EspolioS' : 'Certidao.EspolioN'))->click();

            // Clica em "Emitir"
            $webDriver->findElement(WebDriverBy::cssSelector('input[type="submit"][value="Emitir"]'))->click();

            // Espera nova janela e muda o foco
            $webDriver->wait(5)->until(fn($driver) => count($driver->getWindowHandles()) > 1);
            $windows = $webDriver->getWindowHandles();
            $webDriver->switchTo()->window(end($windows));

            if ($tipo === 'CNPJ') {
                // Se for CNPJ, clica em "Sim" antes do download
                $webDriver->wait(10)->until(
                    WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::id('Certidao.ConfirmaNomeContribuinteSim'))
                )->click();

                // Espera nova aba do PDF abrir
                $webDriver->wait(5)->until(fn($driver) => count($driver->getWindowHandles()) > 1);
                $windows = $webDriver->getWindowHandles();
                $webDriver->switchTo()->window(end($windows));
            }
            
            // Aguarda botão de download visível e clica
            $webDriver->wait(15)->until(
                WebDriverExpectedCondition::elementToBeClickable(WebDriverBy::id('download'))
            )->click();

            // Aguarda o download terminar (ajuste conforme necessário)
            sleep(6);

            $this->info("Certidão baixada com sucesso em: {$downloadPath}");

        } catch (NoSuchWindowException $e) {
            $this->error("Janela foi fechada inesperadamente. " . $e->getMessage());
        } catch (\Exception $e) {
            $this->error("Erro ao emitir certidão: " . $e->getMessage());
        }
    }
}
