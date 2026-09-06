<?php

/**
 * Tenant Session Service
 *
 * Holds the tenant the current request acts as, in the PHP session.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/proposal.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCP\ISession;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The tenant the session is currently acting as.
 *
 * WHY THE SESSION AND NOT THE REQUEST. The tenant a request acts as used to
 * come from an `X-Tenant-Id` header, which the caller supplies — so the caller
 * chose their own tenant, and the only check that would have caught a forged
 * value ran solely on requests carrying a Bearer token. A logged-in user could
 * therefore name any tenant and be believed.
 *
 * The JWT claim is not the answer either. Making the token authoritative would
 * mean a tenant switch could not happen without reminting it, which turns an
 * ordinary UI action into a credential operation.
 *
 * So the session decides, a switch is an explicit act, and membership is
 * verified at the moment of switching rather than trusted per request.
 *
 * @spec openspec/specs/multi-tenancy/spec.md#req-002-user-to-tenant-resolution-via-or-organisation-with-nc-group-fallback
 */
class TenantSessionService {
	/**
	 * Session key holding the active tenant id.
	 *
	 * @var string
	 */
	public const SESSION_KEY = 'dossiq.activeTenantId';

	/**
	 * Constructor.
	 *
	 * @param ISession               $session The PHP session.
	 * @param IUserSession           $users   The user session.
	 * @param TenantAuthenticationService $auth Membership lookups.
	 * @param LoggerInterface        $logger  The logger.
	 */
	public function __construct(
		private readonly ISession $session,
		private readonly IUserSession $users,
		private readonly TenantAuthenticationService $auth,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The uid of the signed-in user, or '' when anonymous.
	 *
	 * @return string The uid.
	 */
	private function uid(): string {
		$user = $this->users->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end uid()

	/**
	 * The tenant this session acts as, or null.
	 *
	 * The stored value is RE-VERIFIED on every read rather than trusted. A
	 * session outlives the membership that justified it: revoking someone's
	 * access to a tenant must take effect on their next request, not on their
	 * next login. Checking here is what makes revocation mean anything.
	 *
	 * A user with exactly one membership and no stored choice resolves to that
	 * one, so ordinary single-tenant use needs no switch. Several memberships
	 * and no choice resolves to nothing: guessing which of them the user meant
	 * would be picking a tenant on their behalf, which is the thing this class
	 * exists to stop.
	 *
	 * @return string|null The tenant id, or null when none is resolved.
	 *
	 * @spec openspec/specs/multi-tenancy/spec.md#req-002-user-to-tenant-resolution-via-or-organisation-with-nc-group-fallback
	 */
	public function activeTenantId(): ?string {
		$uid = $this->uid();
		if ($uid === '') {
			return null;
		}

		try {
			$memberships = $this->auth->listTenantsForUser(userId: $uid);
		} catch (Throwable $e) {
			// Fail CLOSED: an unreadable membership list is not "no tenant",
			// but binding nothing is the safe reading of an unknown answer.
			$this->logger->error(
				'Dossiq: membership lookup failed while resolving the session tenant',
				['uid' => $uid, 'exception' => $e->getMessage()]
			);

			return null;
		}

		$stored = trim((string)($this->session->get(self::SESSION_KEY) ?? ''));
		if ($stored !== '') {
			if (in_array($stored, $memberships, true) === true) {
				return $stored;
			}

			// The membership that justified this choice is gone. Drop it rather
			// than keep serving it.
			$this->clear();
			$this->logger->info(
				'Dossiq: stored tenant is no longer a membership; cleared',
				['uid' => $uid, 'tenantId' => $stored]
			);
		}

		if (count($memberships) === 1) {
			return $memberships[0];
		}

		return null;
	}//end activeTenantId()

	/**
	 * Switch the session to a tenant the user belongs to.
	 *
	 * @param string $tenantId The tenant to switch to.
	 *
	 * @return bool Whether the switch was permitted and applied.
	 *
	 * @spec openspec/specs/multi-tenancy/spec.md#req-002-user-to-tenant-resolution-via-or-organisation-with-nc-group-fallback
	 */
	public function switchTo(string $tenantId): bool {
		$tenantId = trim($tenantId);
		if ($tenantId === '') {
			return false;
		}

		$uid = $this->uid();
		if ($uid === '') {
			return false;
		}

		try {
			$permitted = $this->auth->isMemberOf(tenantId: $tenantId, userId: $uid);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: membership lookup failed during tenant switch (refused)',
				['uid' => $uid, 'tenantId' => $tenantId, 'exception' => $e->getMessage()]
			);

			return false;
		}

		if ($permitted === false) {
			$this->logger->warning(
				'Dossiq: refused a tenant switch to a tenant the user does not belong to',
				['uid' => $uid, 'tenantId' => $tenantId]
			);

			return false;
		}

		$this->session->set(self::SESSION_KEY, $tenantId);

		return true;
	}//end switchTo()

	/**
	 * Forget the session's tenant choice.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/multi-tenancy/spec.md#req-002-user-to-tenant-resolution-via-or-organisation-with-nc-group-fallback
	 */
	public function clear(): void {
		$this->session->remove(self::SESSION_KEY);
	}//end clear()
}//end class
