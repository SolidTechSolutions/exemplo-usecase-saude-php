<?php
declare(strict_types=1);

/**
 * [EN]    Signs a health document (prescription, medical certificate, exam request or report)
 *         with an ICP-Brasil health-sector document-type OID plus the professional's OID
 *         (registration/UF/specialty), and stamps a visible rubric — using a SolidSign
 *         KMS-custodied certificate (POST /solidsign/dsig/pdf/sign-kms).
 * [PT-BR] Assina um documento de saúde (receita, atestado, pedido de exame ou laudo) com o OID
 *         de tipo de documento de saúde ICP-Brasil mais o OID do profissional
 *         (registro/UF/especialidade), e estampa uma rubrica visível.
 */

namespace SolidSign;

use GuzzleHttp\Client;

class SaudeSigningService
{
    private const DOCUMENT_TYPE_OID = [
        'prescricao' => '2.16.76.1.12.1.1',
        'atestado' => '2.16.76.1.12.1.2',
        'exame' => '2.16.76.1.12.1.3',
        'laudo' => '2.16.76.1.12.1.4',
    ];

    private Client $client;

    public function __construct()
    {
        $this->client = new Client(['http_errors' => false]);
    }

    public function sign(
        string $content, string $filename, string $kmsCode, string $documentType,
        string $professionalName, string $professionalRegistro, string $professionalUf, string $professionalEspecialidade
    ): ?string {
        $documentTypeOid = self::DOCUMENT_TYPE_OID[$documentType] ?? null;
        if ($documentTypeOid === null) {
            error_log("Unknown documentType '$documentType'.");
            return null;
        }

        $baseUrl = rtrim($_ENV['SOLIDSIGN_API_BASE_URL'] ?? '', '/');
        $auth = $_ENV['SOLIDSIGN_API_AUTHORIZATION'] ?? '';
        $profOidRegistro = $_ENV['SOLIDSIGN_SAUDE_PROF_OID_REGISTRO'] ?? '';
        $profOidUf = $_ENV['SOLIDSIGN_SAUDE_PROF_OID_UF'] ?? '';
        $profOidEspecialidade = $_ENV['SOLIDSIGN_SAUDE_PROF_OID_ESPECIALIDADE'] ?? '';

        $documentInfoMetadata = json_encode([
            $documentTypeOid => '',
            $profOidRegistro => $professionalRegistro,
            $profOidUf => $professionalUf,
            $profOidEspecialidade => $professionalEspecialidade,
        ]);
        $signatureFieldConfig = json_encode([
            'pageNumber' => (int) ($_ENV['SOLIDSIGN_SAUDE_RUBRIC_PAGE'] ?? 1),
            'coordinateX' => (int) ($_ENV['SOLIDSIGN_SAUDE_RUBRIC_X'] ?? 60),
            'coordinateY' => (int) ($_ENV['SOLIDSIGN_SAUDE_RUBRIC_Y'] ?? 60),
            'width' => (int) ($_ENV['SOLIDSIGN_SAUDE_RUBRIC_WIDTH'] ?? 200),
            'height' => (int) ($_ENV['SOLIDSIGN_SAUDE_RUBRIC_HEIGHT'] ?? 70),
        ]);
        $signatureTextConfig = json_encode([
            'text' => "Dr(a). $professionalName — CRM $professionalRegistro/$professionalUf",
            'fontSize' => (int) ($_ENV['SOLIDSIGN_SAUDE_RUBRIC_FONT_SIZE'] ?? 9),
        ]);

        $multipart = [
            ['name' => 'document[0]', 'contents' => $content, 'filename' => $filename],
            ['name' => 'kmsCode', 'contents' => $kmsCode],
            ['name' => 'profile', 'contents' => $_ENV['SOLIDSIGN_SAUDE_PROFILE'] ?? 'ADRB'],
            ['name' => 'hashAlgorithm', 'contents' => $_ENV['SOLIDSIGN_SAUDE_HASH_ALGORITHM'] ?? 'SHA256'],
            ['name' => 'documentInfoMetadata', 'contents' => $documentInfoMetadata],
            ['name' => 'signatureFieldConfig[0]', 'contents' => $signatureFieldConfig],
            ['name' => 'signatureTextConfig[0]', 'contents' => $signatureTextConfig],
        ];

        $response = $this->client->post("$baseUrl/solidsign/dsig/pdf/sign-kms", [
            'headers' => ['Authorization' => $auth],
            'multipart' => $multipart,
        ]);

        if ($response->getStatusCode() >= 400) {
            error_log("SolidSign error {$response->getStatusCode()}: " . $response->getBody());
            return null;
        }

        $signResp = json_decode((string) $response->getBody(), true);
        $doc = ($signResp['documents'] ?? [])[0] ?? null;
        if ($doc === null) {
            error_log('SolidSign response had no documents.');
            return null;
        }

        $href = $doc['_links']['self']['href'] ?? null;
        if ($href === null) {
            foreach ($doc['links'] ?? [] as $link) {
                if (($link['rel'] ?? null) === 'self') { $href = $link['href']; break; }
            }
        }
        if ($href === null) {
            error_log('SolidSign response had no download link.');
            return null;
        }

        $dlResp = $this->client->get($href, ['headers' => ['Authorization' => $auth]]);
        if ($dlResp->getStatusCode() >= 400) return null;

        return (string) $dlResp->getBody();
    }
}
