<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Cache;
use Nfse\Nfse;
use Nfse\Http\NfseContext;
use Nfse\Enums\TipoAmbiente;
use Nfse\Http\Client\SefinClient;

class NfseService
{
    private const LISTAR_MAX_PAGINAS_INICIAL = 4;
    private const LISTAR_MAX_PAGINAS_CONTINUACAO = 6;
    private const LISTAR_TAMANHO_LOTE = 100;
    private const LISTAR_INTERVALO_ENTRE_PAGINAS_US = 250000;
    private const LISTAR_CURSOR_CACHE_DIAS = 30;

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

    public function listar(array $empresa, int $ultimoNsu = 0, bool $resetCursor = false): array
    {
        try {
            $context = $this->criarContexto($empresa);
            $nfse = new Nfse($context);
            $service = $nfse->contribuinte();

            $chaveCursor = $this->obterChaveCursor($empresa);
            if ($resetCursor) {
                Cache::forget($chaveCursor);
            }

            $cursorInformado = max(0, $ultimoNsu);
            $cursorCache = max(0, (int) Cache::get($chaveCursor, 0));

            if ($cursorInformado > 0) {
                $cursorAtual = $cursorInformado;
                $origemCursor = 'request';
            } else {
                $cursorAtual = $cursorCache;
                $origemCursor = $cursorCache > 0 ? 'cache' : 'request';
            }

            $cnpjConsulta = preg_replace('/\D+/', '', $empresa['cnpj'] ?? '');
            $paginasLidas = 0;
            $ultimoNsuProcessado = $cursorAtual;
            $maiorNsu = null;
            $listaNsu = [];
            $eventosIgnorados = 0;
            $deveBuscarMaisRecentes = $cursorAtual === 0;
            $maxPaginas = $deveBuscarMaisRecentes
                ? self::LISTAR_MAX_PAGINAS_INICIAL
                : self::LISTAR_MAX_PAGINAS_CONTINUACAO;
            $pulouParaFim = false;

            do {
                $paginasLidas++;
                $resp = $service->baixarDfe($cursorAtual, $cnpjConsulta ?: null);
                $loteAtual = $resp->listaNsu ?? [];
                $loteSemEventos = $this->removerEventos($loteAtual);
                $eventosIgnorados += count($loteAtual) - count($loteSemEventos);
                $listaNsu = array_merge($listaNsu, $loteSemEventos);

                $maiorNsuResposta = (int) ($resp->maiorNsu ?? 0);
                if ($maiorNsuResposta > 0) {
                    $maiorNsuAtual = (int) ($maiorNsu ?? 0);
                    $maiorNsu = max($maiorNsuAtual, $maiorNsuResposta);
                }

                $ultimoNsuLote = $resp->ultimoNsu;
                if (empty($ultimoNsuLote)) {
                    $ultimoNsuLote = $this->obterMaiorNsuLote($loteAtual);
                }

                if (!empty($ultimoNsuLote)) {
                    $ultimoNsuProcessado = max($ultimoNsuProcessado, (int) $ultimoNsuLote);
                    Cache::put(
                        $chaveCursor,
                        $ultimoNsuProcessado + 1,
                        now()->addDays(self::LISTAR_CURSOR_CACHE_DIAS)
                    );
                }

                if (
                    $deveBuscarMaisRecentes
                    && $paginasLidas === 1
                    && !empty($maiorNsu)
                    && (int) $maiorNsu > $ultimoNsuProcessado
                ) {
                    $cursorAtual = max(0, (int) $maiorNsu - (self::LISTAR_TAMANHO_LOTE - 1));
                    $listaNsu = [];
                    $pulouParaFim = true;
                    continue;
                }

                if (empty($loteAtual)) {
                    break;
                }

                if (!empty($maiorNsu) && $ultimoNsuProcessado >= (int) $maiorNsu) {
                    break;
                }

                $proximoCursor = $ultimoNsuProcessado + 1;
                if ($proximoCursor <= $cursorAtual) {
                    break;
                }

                $cursorAtual = $proximoCursor;

                if ($paginasLidas < $maxPaginas) {
                    usleep(self::LISTAR_INTERVALO_ENTRE_PAGINAS_US);
                }
            } while ($paginasLidas < $maxPaginas);

            $listaNsu = $this->deduplicarEOrdenarPorNsuDesc($listaNsu);
            $atingiuLimitePaginas = $paginasLidas >= $maxPaginas;
            $existeMaisNsu = !empty($maiorNsu) && $ultimoNsuProcessado < (int) $maiorNsu;

            return [
                'success' => true,
                'data' => [
                    'cnpjConsulta' => $cnpjConsulta,
                    'cursorSource' => $origemCursor,
                    'cursorUsed' => $cursorAtual,
                    'ultNSU' => $ultimoNsuProcessado,
                    'maxNSU' => $maiorNsu ?? $ultimoNsuProcessado,
                    'nextNSU' => $ultimoNsuProcessado + 1,
                    'hasMore' => $atingiuLimitePaginas || $existeMaisNsu,
                    'pagesRead' => $paginasLidas,
                    'jumpedToEnd' => $pulouParaFim,
                    'ignoredEventDocs' => $eventosIgnorados,
                    'list' => $listaNsu,
                ]
            ];
        } catch (Exception $e) {
            $mensagem = $e->getMessage();
            $codigo = (int) $e->getCode();
            $rateLimited = $codigo === 429 || str_contains($mensagem, '429');

            if ($rateLimited) {
                return [
                    'success' => false,
                    'error' => 'API NFS-e retornou 429 (limite de requisicoes). Aguarde 60 segundos e tente novamente com o ultimo_nsu retornado anteriormente.',
                    'retry_after_seconds' => 60,
                ];
            }

            return [
                'success' => false,
                'error' => $mensagem,
            ];
        }
    }

    private function obterChaveCursor(array $empresa): string
    {
        $ambiente = $empresa['ambiente'] ?? 'prod';
        $cnpj = preg_replace('/\D+/', '', $empresa['cnpj'] ?? '');

        return "nfse:listar:cursor:{$ambiente}:{$cnpj}";
    }

    private function removerEventos(array $itens): array
    {
        return array_values(array_filter(
            $itens,
            fn ($item) => !$this->ehEventoXml($item->dfeXmlGZipB64 ?? null)
        ));
    }

    private function ehEventoXml(mixed $xmlZipBase64): bool
    {
        if (!is_string($xmlZipBase64) || $xmlZipBase64 === '') {
            return false;
        }

        $gzip = base64_decode($xmlZipBase64, true);
        if ($gzip === false) {
            return false;
        }

        $xml = @gzdecode($gzip);
        if ($xml === false || $xml === '') {
            return false;
        }

        if (!preg_match('/<\s*([a-zA-Z_][a-zA-Z0-9_:\-\.]*)\b/', $xml, $matches)) {
            return false;
        }

        $nomeTag = strtolower($matches[1]);
        $partes = explode(':', $nomeTag);
        $raiz = end($partes);

        return $raiz === 'evento';
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
            $motivo = trim((string) $motivo);

            $codigoOriginal = preg_replace('/\D+/', '', (string) $codigoEvento);
            $codigoEvento = $this->normalizarCodigoEventoManifestacao($codigoOriginal);

            $chave = preg_replace('/\D+/', '', $chave);
            if (strlen($chave) !== 50) {
                throw new Exception('Chave NFSe invalida para manifestacao.');
            }

            $cnpjAutor = preg_replace('/\D+/', '', (string) ($empresa['cnpj'] ?? ''));
            if (strlen($cnpjAutor) !== 14) {
                throw new Exception('CNPJ do autor invalido para manifestacao.');
            }

            $detalhesXml = $this->montarDetalhesEventoManifestacao($codigoOriginal, $codigoEvento, $motivo);
            // Em producao da SEFIN Nacional, o Id aceito segue PRE + chave(50) + tipoEvento(6).
            // O elemento nPedRegEvento nao e aceito no infPedReg pelo validador da SEFIN.
            $id = "PRE{$chave}{$codigoEvento}";
            $dhEvento = date('Y-m-d\TH:i:sP');
            $tpAmb = $context->ambiente->value;
            $verAplic = '1.0.0';

            $cert = new \Nfse\Signer\Certificate($context->certificatePath, $context->certificatePassword);
            $signer = new \Nfse\Signer\XmlSigner($cert);
            $totalDetalhes = count($detalhesXml);
            $ultimoErro = null;

            foreach ($detalhesXml as $indiceDetalhe => $detalheXml) {
                try {
                    $xmlRef = <<<XML
<pedRegEvento versao="1.01" xmlns="http://www.sped.fazenda.gov.br/nfse">
<infPedReg Id="{$id}">
<tpAmb>{$tpAmb}</tpAmb>
<verAplic>{$verAplic}</verAplic>
<dhEvento>{$dhEvento}</dhEvento>
<CNPJAutor>{$cnpjAutor}</CNPJAutor>
<chNFSe>{$chave}</chNFSe>
{$detalheXml}
</infPedReg>
</pedRegEvento>
XML;

                    $xmlString = str_replace(["\n", "\r", "\t"], '', $xmlRef);
                    $signedXml = $signer->sign($xmlString, 'infPedReg');
                    $payload = base64_encode(gzencode($signedXml));
                    $resp = $service->registrarEvento($chave, $payload);

                    return [
                        'success' => true,
                        'data' => $resp,
                    ];
                } catch (Exception $e) {
                    $ultimoErro = $e;
                    $possuiNovaTentativa = ($indiceDetalhe + 1) < $totalDetalhes;

                    if (!$possuiNovaTentativa || !$this->deveTentarOutroXDesc($e->getMessage())) {
                        throw $e;
                    }
                }
            }

            if ($ultimoErro instanceof Exception) {
                throw $ultimoErro;
            }

            throw new Exception('Falha ao registrar evento de manifestacao.');
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function normalizarCodigoEventoManifestacao(string $codigoEvento): string
    {
        return match ($codigoEvento) {
            '1', '2', '101101' => '101101',
            // Compatibilidade com codigos legados da tela de manifestacao.
            '105101', '105102' => '203202',
            '105103', '105104' => '203206',
            default => $codigoEvento,
        };
    }

    private function montarDetalhesEventoManifestacao(string $codigoOriginal, string $codigoEvento, string $motivo): array
    {
        switch ($codigoEvento) {
            case '101101':
                $codigoMotivo = in_array($motivo, ['1', '2', '9'], true) ? $motivo : '1';
                $motivoCancelamento = trim($motivo);
                if ($motivoCancelamento === '' || in_array($motivoCancelamento, ['1', '2', '9'], true)) {
                    $motivoCancelamento = 'Cancelamento solicitado via API';
                }
                if ($this->tamanhoTexto($motivoCancelamento) < 15) {
                    $motivoCancelamento = str_pad($motivoCancelamento, 15, '.');
                }
                $motivoCancelamento = htmlspecialchars($motivoCancelamento, ENT_XML1 | ENT_QUOTES, 'UTF-8');

                return ["<e101101><xDesc>Cancelamento de NFS-e</xDesc><cMotivo>{$codigoMotivo}</cMotivo><xMotivo>{$motivoCancelamento}</xMotivo></e101101>"];

            case '203202':
                return [
                    '<e203202><xDesc>Manifesta&#xE7;&#xE3;o de NFS-e - Confirma&#xE7;&#xE3;o do Tomador</xDesc></e203202>',
                    '<e203202><xDesc>Confirma&#xE7;&#xE3;o do Tomador</xDesc></e203202>',
                    '<e203202><xDesc>Manifestacao de NFS-e - Confirmacao do Tomador</xDesc></e203202>',
                    '<e203202><xDesc>Confirmacao do Tomador</xDesc></e203202>',
                ];

            case '203206':
                if (in_array($codigoOriginal, ['105103', '105104'], true) && $motivo === '') {
                    throw new Exception('Justificativa obrigatoria para este evento.');
                }

                $codigoMotivo = match ($codigoOriginal) {
                    '105103' => '3', // Nao ocorrencia do fato gerador
                    '105104' => '9', // Outros (desconhecimento)
                    default => '9',
                };

                $motivoRejeicao = $motivo !== '' ? $motivo : 'Manifestacao de rejeicao registrada via API';
                if ($this->tamanhoTexto($motivoRejeicao) < 15) {
                    throw new Exception('Justificativa deve conter no minimo 15 caracteres.');
                }
                $motivoRejeicao = htmlspecialchars($motivoRejeicao, ENT_XML1 | ENT_QUOTES, 'UTF-8');

                return [
                    "<e203206><xDesc>Manifesta&#xE7;&#xE3;o de NFS-e - Rejei&#xE7;&#xE3;o do Tomador</xDesc><infRej><cMotivo>{$codigoMotivo}</cMotivo><xMotivo>{$motivoRejeicao}</xMotivo></infRej></e203206>",
                    "<e203206><xDesc>Rejei&#xE7;&#xE3;o do Tomador</xDesc><infRej><cMotivo>{$codigoMotivo}</cMotivo><xMotivo>{$motivoRejeicao}</xMotivo></infRej></e203206>",
                    "<e203206><xDesc>Manifestacao de NFS-e - Rejeicao do Tomador</xDesc><infRej><cMotivo>{$codigoMotivo}</cMotivo><xMotivo>{$motivoRejeicao}</xMotivo></infRej></e203206>",
                    "<e203206><xDesc>Rejeicao do Tomador</xDesc><infRej><cMotivo>{$codigoMotivo}</cMotivo><xMotivo>{$motivoRejeicao}</xMotivo></infRej></e203206>",
                ];

            default:
                throw new Exception("Codigo de evento nao suportado: {$codigoOriginal}");
        }
    }

    private function deveTentarOutroXDesc(string $mensagemErro): bool
    {
        return str_contains($mensagemErro, 'xDesc')
            && str_contains($mensagemErro, 'Enumeration constraint failed');
    }

    private function tamanhoTexto(string $texto): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($texto, 'UTF-8');
        }

        return strlen($texto);
    }
}

