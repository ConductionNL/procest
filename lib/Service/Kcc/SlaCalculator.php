<?php

/**
 * Dossiq KCC SLA Calculator
 *
 * Pure working-day / SLA-deadline arithmetic for KlantContactCentrum
 * contact moments and callbacks. Respects Dutch public holidays and
 * weekends. Has no external dependencies so it is fully unit-testable.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Deterministic SLA / working-day calculator for the KCC integration.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
 */
class SlaCalculator {
	/**
	 * Default SLA targets in seconds per channel.
	 *
	 * - phone: 2.8 minutes (168s) handle-time target.
	 * - chat: 1 hour first-response.
	 * - email / web_form: 2 working days.
	 *
	 * @var array<string, int>
	 */
	public const CHANNEL_SLA_SECONDS = [
		'phone' => 168,
		'chat' => 3600,
		'social' => 3600,
		'email' => (2 * 8 * 3600),
		'web_form' => (2 * 8 * 3600),
		'letter' => (5 * 8 * 3600),
	];

	/**
	 * Email/letter SLA is expressed in working days rather than raw seconds.
	 *
	 * @var array<string, int>
	 */
	private const CHANNEL_SLA_WORKING_DAYS = [
		'email' => 2,
		'web_form' => 2,
		'letter' => 5,
	];

	/**
	 * Determine whether a date is a weekend day.
	 *
	 * @param DateTimeInterface $date The date to inspect.
	 *
	 * @return bool True for Saturday or Sunday.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function isWeekend(DateTimeInterface $date): bool {
		$dow = (int)$date->format('N');
		return ($dow === 6 || $dow === 7);
	}//end isWeekend()

	/**
	 * Determine whether a date is a Dutch public holiday.
	 *
	 * Covers the nationally recognised holidays: Nieuwjaarsdag, Goede Vrijdag,
	 * Eerste/Tweede Paasdag, Koningsdag, Bevrijdingsdag, Hemelvaartsdag,
	 * Eerste/Tweede Pinksterdag, Eerste/Tweede Kerstdag.
	 *
	 * @param DateTimeInterface $date The date to inspect.
	 *
	 * @return bool True when the date is a recognised public holiday.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function isDutchHoliday(DateTimeInterface $date): bool {
		$year = (int)$date->format('Y');
		$key = $date->format('Y-m-d');

		return in_array($key, $this->dutchHolidays(year: $year), true);
	}//end isDutchHoliday()

	/**
	 * Determine whether a date is a working day (not weekend, not holiday).
	 *
	 * @param DateTimeInterface $date The date to inspect.
	 *
	 * @return bool True for a working day.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function isWorkingDay(DateTimeInterface $date): bool {
		return ($this->isWeekend(date: $date) === false && $this->isDutchHoliday(date: $date) === false);
	}//end isWorkingDay()

	/**
	 * Add a number of working days to a starting date.
	 *
	 * The time-of-day component of the start date is preserved.
	 *
	 * @param DateTimeImmutable $start The starting date-time.
	 * @param int $days Number of working days to add (>= 0).
	 *
	 * @return DateTimeImmutable The resulting date-time.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function addWorkingDays(DateTimeImmutable $start, int $days): DateTimeImmutable {
		$result = $start;
		$remaining = max(0, $days);

		while ($remaining > 0) {
			$result = $result->modify('+1 day');
			if ($this->isWorkingDay(date: $result) === true) {
				$remaining--;
			}
		}

		return $result;
	}//end addWorkingDays()

	/**
	 * Count the working days in an inclusive date range.
	 *
	 * @param DateTimeImmutable $start Range start (inclusive).
	 * @param DateTimeImmutable $end Range end (inclusive).
	 *
	 * @return int Number of working days in the range (0 when end < start).
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function countWorkingDays(DateTimeImmutable $start, DateTimeImmutable $end): int {
		$startDay = $start->setTime(0, 0);
		$endDay = $end->setTime(0, 0);

		if ($endDay < $startDay) {
			return 0;
		}

		$count = 0;
		$cursor = $startDay;
		while ($cursor <= $endDay) {
			if ($this->isWorkingDay(date: $cursor) === true) {
				$count++;
			}

			$cursor = $cursor->modify('+1 day');
		}

		return $count;
	}//end countWorkingDays()

	/**
	 * Compute the SLA deadline for a contact moment on a given channel.
	 *
	 * Working-day channels (email, web_form, letter) advance by whole working
	 * days; real-time channels (phone, chat, social) add the raw second target.
	 *
	 * @param string $channel The contact channel.
	 * @param DateTimeImmutable $start The contact start time.
	 *
	 * @return DateTimeImmutable The SLA deadline.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function deadlineFor(string $channel, DateTimeImmutable $start): DateTimeImmutable {
		if (isset(self::CHANNEL_SLA_WORKING_DAYS[$channel]) === true) {
			return $this->addWorkingDays(start: $start, days: self::CHANNEL_SLA_WORKING_DAYS[$channel]);
		}

		$seconds = (int)(self::CHANNEL_SLA_SECONDS[$channel] ?? self::CHANNEL_SLA_SECONDS['chat']);
		return $start->add(new DateInterval('PT' . $seconds . 'S'));
	}//end deadlineFor()

	/**
	 * Determine whether an SLA has been breached at a reference time.
	 *
	 * @param string $channel The contact channel.
	 * @param DateTimeImmutable $start The contact start time.
	 * @param DateTimeImmutable $now The reference (current) time.
	 *
	 * @return bool True when the deadline has passed.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function isBreached(string $channel, DateTimeImmutable $start, DateTimeImmutable $now): bool {
		return ($now > $this->deadlineFor(channel: $channel, start: $start));
	}//end isBreached()

	/**
	 * Compute the retry time for a callback attempt with exponential backoff.
	 *
	 * Backoff doubles per attempt from a 15-minute base, capped at 24h.
	 *
	 * @param DateTimeImmutable $from The time the attempt failed.
	 * @param int $attemptCount The number of attempts already made (>= 0).
	 *
	 * @return DateTimeImmutable The next attempt time.
	 *
	 * @spec openspec/changes/kcc-klantcontact-integratie/tasks.md#TASK-KCC-25
	 */
	public function nextRetryAt(DateTimeImmutable $from, int $attemptCount): DateTimeImmutable {
		$baseMinutes = 15;
		$factor = (2 ** max(0, $attemptCount));
		$minutes = (int)min(($baseMinutes * $factor), (24 * 60));

		return $from->add(new DateInterval('PT' . $minutes . 'M'));
	}//end nextRetryAt()

	/**
	 * Compute the set of Dutch public holidays for a calendar year.
	 *
	 * @param int $year The calendar year.
	 *
	 * @return array<int, string> Holiday dates as 'Y-m-d' strings.
	 */
	private function dutchHolidays(int $year): array {
		$fixed = [
			$year . '-01-01',
			$year . '-04-27',
			$year . '-05-05',
			$year . '-12-25',
			$year . '-12-26',
		];

		// Easter Sunday (Western) via the well-known anonymous Gregorian
		// algorithm; PHP's easter_date() depends on the calendar extension.
		$easter = $this->easterDate(year: $year);

		$goodFriday = $easter->modify('-2 days');
		$easterMonday = $easter->modify('+1 day');
		$ascension = $easter->modify('+39 days');
		$pentecost = $easter->modify('+49 days');
		$pentecostMon = $easter->modify('+50 days');

		$movable = [
			$goodFriday->format('Y-m-d'),
			$easter->format('Y-m-d'),
			$easterMonday->format('Y-m-d'),
			$ascension->format('Y-m-d'),
			$pentecost->format('Y-m-d'),
			$pentecostMon->format('Y-m-d'),
		];

		return array_merge($fixed, $movable);
	}//end dutchHolidays()

	/**
	 * Compute Western Easter Sunday for a year (anonymous Gregorian algorithm).
	 *
	 * The single-letter locals are the canonical names from the published
	 * algorithm and are kept verbatim for verifiability.
	 *
	 * @param int $year The calendar year.
	 *
	 * @return DateTimeImmutable Easter Sunday at midnight UTC.
	 *
	 * @SuppressWarnings(PHPMD.ShortVariable)
	 */
	private function easterDate(int $year): DateTimeImmutable {
		$a = ($year % 19);
		$b = intdiv($year, 100);
		$c = ($year % 100);
		$d = intdiv($b, 4);
		$e = ($b % 4);
		$f = intdiv(($b + 8), 25);
		$g = intdiv(($b - $f + 1), 3);
		$h = (((19 * $a) + $b - $d - $g + 15) % 30);
		$i = intdiv($c, 4);
		$k = ($c % 4);
		$l = ((32 + (2 * $e) + (2 * $i) - $h - $k) % 7);
		$m = intdiv(($a + (11 * $h) + (22 * $l)), 451);
		$month = intdiv(($h + $l - (7 * $m) + 114), 31);
		$day = ((($h + $l - (7 * $m) + 114) % 31) + 1);

		return new DateTimeImmutable(
			sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day),
			new DateTimeZone('UTC')
		);
	}//end easterDate()
}//end class
