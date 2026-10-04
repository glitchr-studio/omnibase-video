<?php

namespace Base\Video\Controller\Client;

use Base\Video\Service\Uploads;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * tusd's hooks (pre-create, post-finish). Called from inside (tusd ->
 * web:80); the proxy refuses this address from outside. Whatever it is
 * sent, the answer is decided by the upload token (Service\Uploads), never
 * by who calls.
 */
class UploadController extends AbstractController
{
    #[Route('/video/upload/hook', name: 'video_upload_hook', methods: ['POST'])]
    public function hook(Request $request, Uploads $uploads): Response
    {
        $hook = json_decode($request->getContent() ?: '{}', true);
        if (!\is_array($hook)) {
            return new JsonResponse(['RejectUpload' => true, 'HTTPResponse' => ['StatusCode' => 400, 'Body' => 'bad hook']]);
        }

        return new JsonResponse((object) $uploads->hook($hook));
    }
}
