<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\NfseService;

class NfseController extends Controller
{
    protected NfseService $service;

    public function __construct(NfseService $service)
    {
        $this->service = $service;
    }

    public function listar(Request $request)
    {
        $data = $request->validate([
            'empresa.ambiente' => 'required|in:prod,homolog',
            'empresa.razao_social' => 'nullable|string',
            'empresa.cnpj' => 'required|string',
            'empresa.im' => 'nullable|string',
            'empresa.uf' => 'nullable|string',
            'empresa.codigo_municipio' => 'nullable|string',
            'empresa.cert_base64' => 'required|string',
            'empresa.cert_senha' => 'required|string',
            'ultimo_nsu' => 'nullable|integer',
            'reset_cursor' => 'nullable|boolean',
        ]);

        $empresa = $data['empresa'];
        $ultimoNsu = $data['ultimo_nsu'] ?? 0;
        $resetCursor = (bool) ($data['reset_cursor'] ?? false);

        $resultado = $this->service->listar($empresa, $ultimoNsu, $resetCursor);

        if (!$resultado['success']) {
            return response()->json($resultado, 400);
        }

        return response()->json($resultado);
    }

    public function xml(Request $request, string $chave)
    {
        $data = $request->validate([
            'empresa.ambiente' => 'required|in:prod,homolog',
            'empresa.cnpj' => 'required|string',
            'empresa.cert_base64' => 'required|string',
            'empresa.cert_senha' => 'required|string',
        ]);

        $empresa = $data['empresa'];

        $xml = $this->service->xml($empresa, $chave);

        if (empty($xml)) {
            return response()->json(['error' => 'XML não encontrado ou erro na busca'], 404);
        }

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => "attachment; filename=\"{$chave}.xml\"",
        ]);
    }

    public function download(Request $request, string $chave)
    {
        $data = $request->validate([
            'empresa.ambiente' => 'required|in:prod,homolog',
            'empresa.cnpj' => 'required|string',
            'empresa.cert_base64' => 'required|string',
            'empresa.cert_senha' => 'required|string',
        ]);

        $empresa = $data['empresa'];

        try {
            $pdfContent = $this->service->downloadPdf($empresa, $chave);

            return response($pdfContent, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"{$chave}.pdf\"",
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao baixar PDF: ' . $e->getMessage()], 400);
        }
    }

    public function manifestar(Request $request, string $chave)
    {
        $data = $request->validate([
            'empresa.ambiente' => 'required|in:prod,homolog',
            'empresa.cnpj' => 'required|string',
            'empresa.cert_base64' => 'required|string',
            'empresa.cert_senha' => 'required|string',
            'codigo' => 'required|string', // Ex: "1"
            'motivo' => 'required|string',
        ]);

        $empresa = $data['empresa'];

        $resultado = $this->service->manifestar(
            $empresa,
            $chave,
            $data['codigo'],
            $data['motivo']
        );

        if (!$resultado['success']) {
            return response()->json($resultado, 400);
        }

        return response()->json($resultado);
    }

    public function contas(Request $request, string $chave)
    {
        return $this->xml($request, $chave);
    }
}
