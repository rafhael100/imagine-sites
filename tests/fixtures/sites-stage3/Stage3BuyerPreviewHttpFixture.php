<?php
declare(strict_types=1);

namespace SitesStage3CiOnly;

use App\Domain\Commerce\OnepageAcceptedOrder;
use App\Infrastructure\Commerce\PostgresOnepageOrders;
use App\Infrastructure\Identity\PostgresPortalBrowserAuthentication;
use Closure;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** In-process HTTP semantics only: not a registered route, socket or public endpoint. */
final readonly class Stage3BuyerPreviewHttpFixture
{
    public const PATH = '/__stage3_ci/buyer-preview-a';
    public const COOKIE = 'stage3_ci_business_session';
    private const SIZE = 5223;
    private const DIGEST = 'ab999fc8c61d4f22ab250c6d5d86442a0ceca24659d1f4173db3580e4694740e';

    public function __construct(
        private PostgresPortalBrowserAuthentication $auth,
        private PostgresOnepageOrders $nativeOrders,
        private OnepageAcceptedOrder $expected,
        private array $correlatedIntake,
        private Closure $isACurrent,
        private Closure $renderExact,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            if ($request->getRealMethod() !== 'GET' || $request->getMethod() !== 'GET' ||
                $request->getRequestUri() !== self::PATH || $request->getContent() !== '') {
                return self::denied();
            }
            $ref = $request->cookies->get(self::COOKIE);
            if (!is_string($ref) || preg_match('/\A[a-f0-9]{64}\z/D',$ref)!==1 ||
                !$this->authorized($ref) || ($this->isACurrent)() !== true) {
                return self::denied();
            }
            $report = ($this->renderExact)();
            if (!is_array($report) || !is_string($report['html'] ?? null) ||
                ($report['sha256'] ?? null) !== self::DIGEST ||
                strlen($report['html']) !== self::SIZE ||
                hash('sha256',$report['html']) !== self::DIGEST ||
                ($report['sourceVersion'] ?? null) !== 12 || ($report['briefRevision'] ?? null) !== 1 ||
                ($report['privatePreview'] ?? null) !== true ||
                ($report['clientFinalAccepted'] ?? null) !== false ||
                ($report['publicationAuthorized'] ?? null) !== false ||
                ($report['canPublish'] ?? null) !== false ||
                !$this->authorized($ref) || ($this->isACurrent)() !== true) {
                return self::denied();
            }
            return new Response($report['html'],200,[
                'Content-Type'=>'text/html; charset=UTF-8',
                'Cache-Control'=>'private, no-store',
                'X-Content-Type-Options'=>'nosniff',
            ]);
        } catch (Throwable) {
            return self::denied();
        }
    }

    private function authorized(#[\SensitiveParameter] string $ref): bool
    {
        if (!$this->auth->isActiveBusinessSession($ref)) return false;
        $order = $this->nativeOrders->findOwnedAcceptedOrder($ref,$this->expected->id);
        if (!$order instanceof OnepageAcceptedOrder || [
            $order->id,$order->buyerId,$order->companyId,$order->companyReference,$order->contractRef,$order->contractRevision,
        ] !== [
            $this->expected->id,$this->expected->buyerId,$this->expected->companyId,$this->expected->companyReference,
            $this->expected->contractRef,$this->expected->contractRevision,
        ]) return false;
        $intake = $this->nativeOrders->findOwnedSiteIntake($ref,$order->id);
        if (!is_array($intake)) return false;
        $expected = $this->correlatedIntake;
        ksort($intake);
        ksort($expected);
        return $intake === $expected && ($intake['state'] ?? null) === 'awaiting_official_authority' &&
            is_string($intake['siteRef'] ?? null) && $intake['siteRef'] !== $order->id;
    }

    private static function denied(): Response
    {
        return new Response('',404,['Cache-Control'=>'private, no-store']);
    }
}
