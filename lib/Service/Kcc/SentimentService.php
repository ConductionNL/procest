<?php

/**
 * Dossiq KCC SentimentService
 *
 * Lightweight Dutch sentiment analyser for KCC contactmoment transcripts.
 * Detects trigger words (klacht, advocaat, wethouder, …), scores polarity
 * from a hand-curated word list, and returns the escalation level the
 * werkplek UI should surface to the medewerker.
 *
 * This is a deterministic algorithm — NOT an LLM call. It runs in-process on
 * every contactmoment so the werkplek can show immediate feedback. Future
 * work may swap in a richer model behind the same {score, label, triggers,
 * escalatieAanbevolen, escalatieLevel} contract.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T09
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

/**
 * Deterministic Dutch sentiment analyser for KCC transcripts.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T09
 */
class SentimentService {
	/**
	 * Default trigger words — Dutch noun list that always escalates the
	 * conversation regardless of polarity score.
	 *
	 * @var array<int, string>
	 */
	public const DEFAULT_TRIGGER_WORDS = [
		'klacht',
		'klagen',
		'advocaat',
		'rechtszaak',
		'rechtbank',
		'media',
		'krant',
		'wethouder',
		'burgemeester',
		'ombudsman',
	];

	/**
	 * Serious triggers that immediately escalate to escalatieLevel=rood.
	 *
	 * @var array<int, string>
	 */
	public const SERIOUS_TRIGGERS = ['advocaat', 'rechtszaak', 'rechtbank', 'media', 'krant', 'ombudsman'];

	/**
	 * Negative sentiment word weights (Dutch).
	 *
	 * @var array<string, float>
	 */
	public const NEGATIVE_WEIGHTS = [
		'boos' => -0.6,
		'kwaad' => -0.6,
		'woedend' => -0.8,
		'pissig' => -0.7,
		'verschrikkelijk' => -0.7,
		'ongelooflijk' => -0.4,
		'belachelijk' => -0.6,
		'schandalig' => -0.7,
		'slecht' => -0.4,
		'niet' => -0.1,
		'fout' => -0.4,
		'verkeerd' => -0.3,
		'teleurgesteld' => -0.5,
		'gefrustreerd' => -0.5,
		'klacht' => -0.5,
		'klagen' => -0.4,
	];

	/**
	 * Positive sentiment word weights (Dutch).
	 *
	 * @var array<string, float>
	 */
	public const POSITIVE_WEIGHTS = [
		'bedankt' => 0.4,
		'fijn' => 0.3,
		'goed' => 0.3,
		'prima' => 0.4,
		'mooi' => 0.3,
		'top' => 0.5,
		'super' => 0.5,
		'tevreden' => 0.5,
		'blij' => 0.4,
		'fantastic' => 0.6,
		'geweldig' => 0.6,
	];

	/**
	 * Analyse a transcript for sentiment + trigger words.
	 *
	 * @param string $text The transcript text (Dutch).
	 * @param array<int, string>|null $triggerWords Optional override list; defaults to
	 *                                              DEFAULT_TRIGGER_WORDS.
	 *
	 * @return array{score: float, label: string, triggers: array<int, string>,
	 *               escalationRecommended: bool, escalationLevel: string}
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T09
	 */
	public function analyzeSentiment(string $text, ?array $triggerWords = null): array {
		$triggers = $this->detectTriggers(
			text: $text,
			triggerWords: $triggerWords ?? self::DEFAULT_TRIGGER_WORDS
		);

		$score = $this->scorePolarity(text: $text);
		$label = $this->labelForScore(score: $score);

		$escalationAdvised = $this->shouldEscalate(score: $score, triggers: $triggers);
		$escalationLevel = $this->getEscalationLevel(score: $score, triggers: $triggers);

		return [
			'score' => $score,
			'label' => $label,
			'triggers' => $triggers,
			'escalationRecommended' => $escalationAdvised,
			'escalationLevel' => $escalationLevel,
		];
	}//end analyzeSentiment()

	/**
	 * Should the contact be escalated to a senior medewerker?
	 *
	 * @param float $score The polarity score.
	 * @param array<int, string> $triggers Detected trigger words.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T09
	 */
	public function shouldEscalate(float $score, array $triggers): bool {
		if ($score <= -0.5) {
			return true;
		}

		foreach ($triggers as $trigger) {
			if (in_array($trigger, self::SERIOUS_TRIGGERS, true) === true) {
				return true;
			}
		}

		return false;
	}//end shouldEscalate()

	/**
	 * Return the four-level escalation badge: geen / geel / oranje / rood.
	 *
	 * @param float $score The polarity score.
	 * @param array<int, string> $triggers Detected trigger words.
	 *
	 * @return string The badge slug.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T09
	 */
	public function getEscalationLevel(float $score, array $triggers): string {
		foreach ($triggers as $trigger) {
			if (in_array($trigger, self::SERIOUS_TRIGGERS, true) === true) {
				return 'rood';
			}
		}

		if ($score < -0.6) {
			return 'rood';
		}

		if ($score < -0.3) {
			return 'oranje';
		}

		if ($score <= 0.0) {
			return 'geel';
		}

		return 'geen';
	}//end getEscalationLevel()

	/**
	 * Detect trigger words in the text using word-boundary matching (Dutch).
	 *
	 * @param string $text The transcript text.
	 * @param array<int, string> $triggerWords The trigger word list.
	 *
	 * @return array<int, string> The detected triggers (lowercased, deduped).
	 */
	private function detectTriggers(string $text, array $triggerWords): array {
		$lower = mb_strtolower($text);
		$found = [];

		foreach ($triggerWords as $word) {
			$needle = mb_strtolower((string)$word);
			if ($needle === '') {
				continue;
			}

			// Word-boundary match: surrounded by non-word characters or string
			// boundaries. preg_quote escapes the needle so callers can safely
			// configure phrases with punctuation.
			$pattern = '/\b' . preg_quote($needle, '/') . '\b/u';
			if (preg_match($pattern, $lower) === 1) {
				$found[$needle] = true;
			}
		}

		return array_keys($found);
	}//end detectTriggers()

	/**
	 * Score polarity using the hand-curated weighted lists.
	 *
	 * @param string $text The transcript text.
	 *
	 * @return float Score in [-1.0, 1.0].
	 */
	private function scorePolarity(string $text): float {
		$lower = mb_strtolower($text);
		$tokens = preg_split('/[^a-zA-Zàáäâèéëêìíïîòóöôùúüû]+/u', $lower);
		if ($tokens === false) {
			$tokens = [];
		}

		$score = 0.0;
		foreach ($tokens as $token) {
			if (isset(self::NEGATIVE_WEIGHTS[$token]) === true) {
				$score += self::NEGATIVE_WEIGHTS[$token];
			} elseif (isset(self::POSITIVE_WEIGHTS[$token]) === true) {
				$score += self::POSITIVE_WEIGHTS[$token];
			}
		}

		// Clamp to [-1, 1] for predictable consumer behaviour.
		if ($score > 1.0) {
			return 1.0;
		}

		if ($score < -1.0) {
			return -1.0;
		}

		return $score;
	}//end scorePolarity()

	/**
	 * Map a polarity score to a Dutch sentiment label.
	 *
	 * @param float $score Polarity in [-1, 1].
	 *
	 * @return string positief|neutraal|negatief|boos
	 */
	private function labelForScore(float $score): string {
		if ($score >= 0.3) {
			return 'positief';
		}

		if ($score > -0.3) {
			return 'neutraal';
		}

		if ($score > -0.6) {
			return 'negatief';
		}

		return 'boos';
	}//end labelForScore()
}//end class
