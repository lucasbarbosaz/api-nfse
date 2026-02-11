<?php

namespace App\Services;

use Exception;
use Nfse\Nfse;
use Nfse\Http\NfseContext;
use Nfse\Enums\TipoAmbiente;
use Nfse\Http\Client\SefinClient;

class NfseService
{
    private const LISTAR_MAX_PAGINAS = 200;
    private const LISTAR_TAMANHO_LOTE = 100;

    protected function criarContexto(array $empresa): NfseContext
    {
        return new NfseContext(
            ambiente: $empresa['ambiente'] === 'prod' ? TipoAmbiente::Producao : TipoAmbiente::Homologacao,
            certificatePath: $this->carregarCertificado($empresa),
            certificatePassword: $empresa['cert_senha']
        );
    }

    protected function carregarCertificado(array $empresa): string
    {
        $hash = substr(md5($empresa['cert_base64']), 0, 8);
        $path = storage_path("certs/{$empresa['cnpj']}_{$hash}.pfx");

        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        if (!file_exists($path)) {
            $pfxContent = base64_decode($empresa['cert_base64']);

            if (function_exists('openssl_pkcs12_read') && function_exists('openssl_pkcs12_export')) {
                $certs = [];
                if (! @openssl_pkcs12_read($pfxContent, $certs, $empresa['cert_senha'])) {
                    $msg = "Nao foi possivel ler o certificado PFX. Verifique a senha ou validade do arquivo. Erro OpenSSL: " . openssl_error_string();
                    throw new \Exception($msg);
                }

                $newPfx = '';
                if (isset($certs['cert']) && isset($certs['pkey'])) {
                    $args = [];
                    if (isset($certs['extracerts'])) {
                        $args['extracerts'] = $certs['extracerts'];
                    }
                    if (@openssl_pkcs12_export($certs['cert'], $newPfx, $certs['pkey'], $empresa['cert_senha'], $args)) {
                        $pfxContent = $newPfx;
                    } else {
                        throw new \Exception("Falha ao re-exportar certificado: " . openssl_error_string());
                    }
                }
            }

            file_put_contents($path, $pfxContent);
        }

        return $path;
    }

    public function listar(array $empresa, int $ultimoNsu = 0): array
    {
        try {
            $context = $this->criarContexto($empresa);
            $nfse = new Nfse($context);
            $service = $nfse->contribuinte();

            $cursorAtual = max(0, $ultimoNsu);
            $paginasLidas = 0;
            $ultimoNsuProcessado = $cursorAtual;
            $maiorNsu = null;
            $listaNsu = [];
            $deveBuscarMaisRecentes = $ultimoNsu === 0;

            do {
                $paginasLidas++;
                $resp = $service->baixarDfe($cursorAtual);
                $loteAtual = $resp->listaNsu ?? [];
                $listaNsu = array_merge($listaNsu, $loteAtual);
                $maiorNsu = $resp->maiorNsu ?? $maiorNsu;

                $ultimoNsuLote = $resp->ultimoNsu;
                if (empty($ultimoNsuLote)) {
                    $ultimoNsuLote = $this->obterMaiorNsuLote($loteAtual);
                }

                if (!empty($ultimoNsuLote)) {
                    $ultimoNsuProcessado = (int) $ultimoNsuLote;
                }

                if (
                    $deveBuscarMaisRecentes
                    && $paginasLidas === 1
                    && !empty($maiorNsu)
                    && (int) $maiorNsu > $ultimoNsuProcessado
                ) {
                    $cursorAtual = max(0, (int) $maiorNsu - (self::LISTAR_TAMANHO_LOTE - 1));
                    $listaNsu = [];
                    continue;
                }

                if (!$deveBuscarMaisRecentes) {
                    break;
                }

                if (empty($loteAtual)) {
                    break;
                }

                if (!empty($maiorNsu) && $ultimoNsuProcessado >= (int) $maiorNsu) {
                    break;
                }

                if ($ultimoNsuProcessado <= $cursorAtual) {
                    break;
                }

                $cursorAtual = $ultimoNsuProcessado;
            } while ($paginasLidas < self::LISTAR_MAX_PAGINAS);

            $listaNsu = $this->deduplicarEOrdenarPorNsuDesc($listaNsu);

            return [
                'success' => true,
                'data' => [
                    'ultNSU' => $ultimoNsuProcessado,
                    'maxNSU' => $maiorNsu ?? $ultimoNsuProcessado,
                    'list' => $listaNsu,
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function obterMaiorNsuLote(array $listaNsu): ?int
    {
        $nsus = array_map(
            fn ($item) => (int) ($item->nsu ?? 0),
            $listaNsu
        );

        $nsus = array_values(array_filter($nsus, fn (int $nsu) => $nsu > 0));
        if (empty($nsus)) {
            return null;
        }

        return max($nsus);
    }

    private function deduplicarEOrdenarPorNsuDesc(array $listaNsu): array
    {
        $porNsu = [];

        foreach ($listaNsu as $item) {
            $nsu = (int) ($item->nsu ?? 0);
            $chave = $nsu > 0 ? (string) $nsu : (string) spl_object_id($item);
            $porNsu[$chave] = $item;
        }

        $resultado = array_values($porNsu);

        usort(
            $resultado,
            fn ($a, $b) => ((int) ($b->nsu ?? 0)) <=> ((int) ($a->nsu ?? 0))
        );

        return $resultado;
    }

    public function xml(array $empresa, string $chave): string
    {
        try {
            $context = $this->criarContexto($empresa);

            $client = new SefinClient($context);
            $resp = $client->consultarNfse($chave);

            if ($resp->nfseXmlGZipB64) {
                return gzdecode(base64_decode($resp->nfseXmlGZipB64));
            }

            return '';
        } catch (Exception $e) {
            return '';
        }
    }


    public function downloadPdf(array $empresa, string $chave): string
    {
        try {
            $context = $this->criarContexto($empresa);
            $nfse = new Nfse($context);
            $service = $nfse->contribuinte();

            return $service->downloadDanfse($chave);
        } catch (Exception $e) {
            throw $e;
        }
    }


    public function manifestar(array $empresa, string $chave, string $codigo, string $motivo = ''): array
    {
        return $this->registrarEvento($empresa, $chave, $codigo, $motivo);
    }

    public function cancelar(array $empresa, string $chave, string $codigo, string $motivo): array
    {
        return $this->manifestar($empresa, $chave, $codigo, $motivo);
    }
    public function registrarEvento(array $empresa, string $chave, string $codigoEvento, string $motivo = ''): array
    {
        try {
            $context = $this->criarContexto($empresa);
            $service = (new Nfse($context))->contribuinte();

            if (in_array($codigoEvento, ['1', '2', '101101'])) {
                $codigoEvento = '101101';
            }

            $detalheXml = '';
            $tagEvento = "e{$codigoEvento}";

            // Tratamento por tipo de evento
            switch ($codigoEvento) {
                case '101101': // Cancelamento
                    $codMotivo = in_array($motivo, ['1', '2']) ? $motivo : '1';
                    $detalheXml = "<{$tagEvento}><codigoMotivo>{$codMotivo}</codigoMotivo><motivo>Cancelamento solicitado</motivo><descricao>Cancelamento via API</descricao></{$tagEvento}>";
                    break;
                case '105101': // Ciência da Emissão
                    $detalheXml = "<{$tagEvento}><descEvento>Ciencia da Emissao</descEvento></{$tagEvento}>";
                    break;
                case '105102': // Confirmação da Prestação
                    $detalheXml = "<{$tagEvento}><descEvento>Confirmacao da Prestacao</descEvento></{$tagEvento}>";
                    break;
                case '105103': // Operação não Realizada
                    if (empty($motivo)) throw new Exception("Justificativa obrigatória para este evento.");
                    $detalheXml = "<{$tagEvento}><descEvento>Operacao nao Realizada</descEvento><xJust>{$motivo}</xJust></{$tagEvento}>";
                    break;
                case '105104': // Desconhecimento da Operação
                    if (empty($motivo)) throw new Exception("Justificativa obrigatória para este evento.");
                    $detalheXml = "<{$tagEvento}><descEvento>Desconhecimento da Operacao</descEvento><xJust>{$motivo}</xJust></{$tagEvento}>";
                    break;
                default:
                    throw new Exception("Código de evento não suportado: $codigoEvento");
            }

            $id = "ID" . $codigoEvento . $chave . "01";
            $dhEvento = date('Y-m-d\TH:i:sP');
            $tpAmb = $context->ambiente->value;
            $verAplic = "1.0.0";
            $cnpjAutor = $empresa['cnpj'];

            $xmlRef = <<<XML
<pedRegEvento versao="1.00" xmlns="http://www.sped.fazenda.gov.br/nfse">
<infPedReg Id="{$id}">
<tpAmb>{$tpAmb}</tpAmb>
<verAplic>{$verAplic}</verAplic>
<dhEvento>{$dhEvento}</dhEvento>
<chNFSe>{$chave}</chNFSe>
<CNPJAutor>{$cnpjAutor}</CNPJAutor>
<nPedRegEvento>1</nPedRegEvento>
{$detalheXml}
</infPedReg>
</pedRegEvento>
XML;

            $xmlString = str_replace(["\n", "\r", "\t"], "", $xmlRef);

            $cert = new \Nfse\Signer\Certificate($context->certificatePath, $context->certificatePassword);
            $signer = new \Nfse\Signer\XmlSigner($cert);
            $signedXml = $signer->sign($xmlString, 'infPedReg');

            // Envio
            $payload = base64_encode(gzencode($signedXml));
            $resp = $service->registrarEvento($chave, $payload);

            return [
                'success' => true,
                'data' => $resp,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
