<?php

namespace App\Services;

use Exception;
use Nfse\Nfse;
use Nfse\Http\NfseContext;
use Nfse\Enums\TipoAmbiente;
use Nfse\Http\Client\SefinClient;

class NfseService
{
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
        $path = storage_path("certs/{$empresa['cnpj']}.pfx");

        // Garante que o diretório existe
        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        if (!file_exists($path)) {
            file_put_contents(
                $path,
                base64_decode($empresa['cert_base64'])
            );
        }

        return $path;
    }

    /**
     * Consulta notas destinadas (Tomadas) ou emitidas via Distribuicao DFe (NSU).
     * Nota: A API Nacional não suporta filtro por período (data inicial/final) na listagem.
     * Utiliza-se o NSU (Número Sequencial Único) para sincronização.
     *
     * @param array $empresa Dados da empresa
     * @param int $ultimoNsu Último NSU sincronizado (padrão 0 para iniciar)
     * @return array
     */
    public function listar(array $empresa, int $ultimoNsu = 0): array
    {
        try {
            $context = $this->criarContexto($empresa);
            $nfse = new Nfse($context);
            $service = $nfse->contribuinte();

            // Baixa documentos a partir do último NSU
            $resp = $service->baixarDfe($ultimoNsu);

            return [
                'success' => true,
                'data' => [
                    'ultNSU' => $resp->ultimoNsu,
                    'maxNSU' => $resp->maiorNsu,
                    'list' => $resp->listaNsu, // Array de DistribuicaoNsuDto
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Obter XML da NFSe por Chave de Acesso
     *
     * @param array $empresa Dados da empresa
     * @param string $chave Chave de acesso da NFSe (50 caracteres)
     * @return string XML decodificado ou vazio em caso de erro
     */
    public function xml(array $empresa, string $chave): string
    {
        try {
            $context = $this->criarContexto($empresa);
            
            // Instanciamos o SefinClient diretamente para obter a resposta bruta com o XML compactado
            $client = new SefinClient($context);
            $resp = $client->consultarNfse($chave);

            if ($resp->nfseXmlGZipB64) {
                return gzdecode(base64_decode($resp->nfseXmlGZipB64));
            }
            
            return '';
        } catch (Exception $e) {
            // Logar erro se necessário
            return '';
        }
    }
}
