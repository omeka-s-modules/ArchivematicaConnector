<?php
namespace ArchivematicaConnector\Controller;

use ArchivematicaConnector\Sword\MetsImporter;
use Doctrine\ORM\EntityManager;
use Laminas\Authentication\AuthenticationServiceInterface;
use Laminas\Mvc\Controller\AbstractActionController;

// Implements a minimal SWORD server endpoint for receiving DIPs from Archivematica
class SwordController extends AbstractActionController
{
    public function __construct(
        private EntityManager $entityManager,
        private AuthenticationServiceInterface $authService,
        private MetsImporter $metsImporter
    ) {}

    // Accepts POST /sword/deposit[/:target] with a raw ZIP body and Basic Auth credentials
    // Returns 201 + Location header on success, matching the SWORD response Archivematica expects
    public function depositAction()
    {
        $request = $this->getRequest();

        if (!$request->isPost()) {
            return $this->errorResponse(405, 'Method Not Allowed');
        }

        $authHeader = $request->getHeaders()->get('Authorization');
        if (!$authHeader || !$this->authenticate($authHeader->getFieldValue())) {
            $response = $this->getResponse();
            $response->setStatusCode(401);
            $response->getHeaders()->addHeaderLine('WWW-Authenticate', 'Basic realm="Omeka S"');
            return $response;
        }

        $body = $request->getContent();
        if (!$body) {
            return $this->errorResponse(400, 'Empty request body');
        }

        $tempZip = tempnam(sys_get_temp_dir(), 'amdip_') . '.zip';
        file_put_contents($tempZip, $body);

        try {
            $importId = $this->metsImporter->import($tempZip);
        } catch (\Exception $e) {
            @unlink($tempZip);
            return $this->errorResponse(500, $e->getMessage());
        }

        @unlink($tempZip);

        $uri = $request->getUri();
        $baseUrl = $uri->getScheme() . '://' . $uri->getHost();

        $response = $this->getResponse();
        $response->setStatusCode(201);
        $response->getHeaders()
            ->addHeaderLine('Content-Type', 'application/atom+xml')
            ->addHeaderLine('Location', $baseUrl . '/api/archivematica_imports/' . $importId);
        $response->setContent(sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>'
            . '<entry xmlns="http://www.w3.org/2005/Atom"><id>%d</id></entry>',
            $importId
        ));
        return $response;
    }

    // Validates Basic Auth credentials against the Omeka user table and writes the
    // authenticated identity so subsequent API calls in MetsImporter respect ACL
    private function authenticate(string $headerValue): bool
    {
        if (!preg_match('/^Basic\s+(.+)$/i', $headerValue, $matches)) {
            return false;
        }
        $decoded = base64_decode($matches[1]);
        if (!$decoded || !str_contains($decoded, ':')) {
            return false;
        }
        [$email, $password] = explode(':', $decoded, 2);

        $user = $this->entityManager
            ->getRepository(\Omeka\Entity\User::class)
            ->findOneBy(['email' => $email, 'isActive' => true]);

        if (!$user || !password_verify($password, $user->getPasswordHash())) {
            return false;
        }

        $this->authService->getStorage()->write($user);
        return true;
    }

    private function errorResponse(int $code, string $message)
    {
        $response = $this->getResponse();
        $response->setStatusCode($code);
        $response->getHeaders()->addHeaderLine('Content-Type', 'application/json');
        $response->setContent(json_encode(['error' => $message]));
        return $response;
    }
}
