<?php
/**
 * @file classes/citation/NlmCitationParserDemultiplexerFilter.inc.php
 *
 * Copyright (c) 2000-2010 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class NlmCitationParserDemultiplexerFilter
 * @ingroup citation
 *
 * @brief A filter takes a number of citation descriptions and
 *  creates a single citation from them guessing "best" values.
 */

// $Id$

import('filter.Filter');

class NlmCitationParserDemultiplexerFilter extends Filter {
	/**
	 * Constructor
	 */
	function NlmCitationParserDemultiplexerFilter() {
		parent::Filter();
	}

	//
	// Implementing abstract template methods from Filter
	//
	/**
	 * @see Filter::supports()
	 * @param $input mixed
	 * @param $output mixed
	 * @return boolean
	 */
	function supports(&$input, &$output) {
		// Check input type
		// Check the number of the input objects
		if (!(is_array($input) && count($input))) return false;

		// Iterate over the input objects and check their type.
		$inputFound = false;
		foreach($input as $metadataDescription) {
			if (!is_null($metadataDescription)) {
				// We need at least one non-null value
				$inputFound = true;

				if (!is_a($metadataDescription, 'MetadataDescription')) return false;
				$metadataSchema = $metadataDescription->getMetadataSchema();
				if (!is_a($metadataSchema, 'NlmCitationSchema')) return false;
			}
		}
		if (!$inputFound) return false;

		// Check output type
		if (is_null($output)) return true;
		if (!is_a($output, 'Citation')) return false;
		return ($output->getCitationState() == CITATION_PARSED);
	}

	/**
	 * @see Filter::process()
	 * @param $input array incoming MetadataDescriptions
	 * @return Citation
	 */
	function &process(&$input) {
		// Initialize the array that will contain parsed citations by score.
		// This is a two-dimensional array that with the score as key and
		// the scored citations as values.
		$scoredCitations = array();

		// Let each configured parser service generate citation meta-data according
		// to its specific implementation.
		foreach ($input as $citationIndex => $parsedCitation) {
			if (is_null($parsedCitation)) continue;
			// FIXME: We should somehow provide feedback to the user
			// about parsers that caused an error.

			// If the publication type is not set, take a guess
			if (!$parsedCitation->hasStatement('[@publication-type]')) {
				$publicationType = $this->_guessPublicationType($parsedCitation);
				if (!is_null($publicationType)) {
					$parsedCitation->addStatement('[@publication-type]', $publicationType);
				}
			}

			// Calculate the score for this parsed citation
			$parseScore = $this->_parseScore($parsedCitation);

			// Save the parsed citation hashed by its parse score.
			// We save them as a sub-array in case several citations
			// receive the same parse score.
			if (!isset($scoredCitations[$parseScore])) {
				$scoredCitations[$parseScore] = array();
			}
			$scoredCitations[$parseScore][] =& $parsedCitation;
			unset ($parsedCitation);
		}

		// Get a single set of "best" values for the citation description
		// and set them in a new citation object.
		$citation =& $this->_guessValues($scoredCitations);
		$citation->setCitationState(CITATION_PARSED);

		return $citation;
	}


	//
	// Private methods
	//
	/**
	 * Try to guess a citation's publication type based on detected elements
	 * @param $metadataDescription MetadataDescription
	 * @return integer one of NLM_PUBLICATION_TYPE_*
	 */
	function _guessPublicationType(&$metadataDescription) {
		// If we already have a publication type, why should we guess one?
		assert(!$metadataDescription->hasStatement('[@publication-type]'));

		// Avoid deducing from a description that has only very few properties set
		// and may therefore be of low quality.
		$descriptionCompletenessIndicators = array(
			'person-group[@person-group-type="editor"]', 'article-title', 'date'
		);
		foreach($descriptionCompletenessIndicators as $descriptionCompletenessIndicator) {
			if (!$metadataDescription->hasStatement($descriptionCompletenessIndicator)) return null;
		}

		// The following property names help us to guess the most probable publication type
		$typicalPropertyNames = array(
			'volume' => NLM_PUBLICATION_TYPE_JOURNAL,
			'issue' => NLM_PUBLICATION_TYPE_JOURNAL,
			'season' => NLM_PUBLICATION_TYPE_JOURNAL,
			'issn[@pub-type="ppub"]' => NLM_PUBLICATION_TYPE_JOURNAL,
			'issn[@pub-type="epub"]' => NLM_PUBLICATION_TYPE_JOURNAL,
			'pub-id[@pub-id-type="pmid"]' => NLM_PUBLICATION_TYPE_JOURNAL,
			'person-group[@person-group-type="editor"]' => NLM_PUBLICATION_TYPE_BOOK,
			'edition' => NLM_PUBLICATION_TYPE_BOOK,
			'chapter-title' => NLM_PUBLICATION_TYPE_BOOK,
			'isbn' => NLM_PUBLICATION_TYPE_BOOK,
			'publisher-name' => NLM_PUBLICATION_TYPE_BOOK,
			'publisher-loc' => NLM_PUBLICATION_TYPE_BOOK,
			'conf-date' => NLM_PUBLICATION_TYPE_CONFPROC,
			'conf-loc' => NLM_PUBLICATION_TYPE_CONFPROC,
			'conf-name' => NLM_PUBLICATION_TYPE_CONFPROC,
			'conf-sponsor' => NLM_PUBLICATION_TYPE_CONFPROC
		);

		$hitCounters = array(
			NLM_PUBLICATION_TYPE_JOURNAL => 0,
			NLM_PUBLICATION_TYPE_BOOK => 0,
			NLM_PUBLICATION_TYPE_CONFPROC => 0
		);
		$highestCounterValue = 0;
		$probablePublicationType = null;
		foreach($typicalPropertyNames as $typicalPropertyName => $currentProbablePublicationType) {
			if ($metadataDescription->hasStatement($currentProbablePublicationType)) {
				// Record the hit
				$hitCounters[$currentProbablePublicationType]++;

				// Is this currently the highest counter value?
				if ($hitCounters[$currentProbablePublicationType] > $highestCounterValue) {
					// This is the highest value
					$highestCounterValue = $hitCounters[$currentProbablePublicationType];
					$probablePublicationType = $currentProbablePublicationType;
				} elseif ($hitCounters[$currentProbablePublicationType] == $highestCounterValue) {
					// There are two counters with the same value, so no unique result
					$probablePublicationType = null;
				}
			}
		}

		// Return the publication type with the highest hit counter.
		return $probablePublicationType;
	}

	/**
	 * Derive a confidence score calculated as the number of statements for a group
	 * of expected properties.
	 * @param MetadataDescription $metadataDescription
	 * @return integer parse score
	 */
	function _parseScore(&$metadataDescription) {
		$expectedProperties = array(
			'person-group[@person-group-type="author"]', 'article-title', 'source',
			'date', 'fpage', '[@publication-type]'
		);
		$setProperties = array_intersect($expectedProperties, $metadataDescription->getSetPropertyNames());
		$parseScore = min(((count($setProperties) / count($expectedProperties))*100), 100);
		return $parseScore;
	}

	/**
	 * Take an array of citation parse/lookup results and derive a citation
	 * with one "best" set of values.
	 *
	 * We determine the best values within the citations that have a score above
	 * the given threshold. Citations with a score below the threshold will be
	 * ignored.
	 *
	 * For these citations we count the frequency of values per meta-data property.
	 * The most frequent value will be chosen as "best" value.
	 *
	 * If two values have the same frequency then decide based on the score. If
	 * this is still ambivalent then return the first of the remaining values.
	 *
	 * This method will also calculate the overall parsing score for the target
	 * citation.
	 *
	 * @param $scoredCitations
	 * @param $scoreThreshold integer a number between 0 (=no threshold) and 100,
	 *  default: no threshold
	 * @return Citation one citation with the "best" values set
	 */
	function &_guessValues(&$scoredCitations, $scoreThreshold = 0) {
		assert($scoreThreshold >= 0 && $scoreThreshold <= 100);

		// Create the target citation description.
		$metadataSchema = new NlmCitationSchema();
		$targetDescription = new MetadataDescription($metadataSchema, ASSOC_TYPE_CITATION);

		// Step 1: List all values and max scores that have been identified for a given element
		//         but only include values from results above a given scoring threshold

		// Initialize variables for the first step.
		$valuesByPropertyName = array();
		$maxScoresByPropertyNameAndValue = array();

		// Sort the scored citations by score with the highest score first.
		krsort($scoredCitations);
		foreach ($scoredCitations as $currentScore => $citationsForCurrentScore) {
			// Check whether the current score is below the threshold, if so
			// stop the loop. We've sorted our citations by score so the remaining
			// citations all have scores below the threshold and we can forget
			// about them.
			if ($currentScore < $scoreThreshold) {
				break;
			}

			foreach($citationsForCurrentScore as $citationForCurrentScore) {
				$statements = $citationForCurrentScore->getStatements();

				// Add the property values and scores of this citation
				// to the overall property lists
				foreach($statements as $propertyName => $value) {
					// Initialize sub-arrays if necessary
					if (!isset($valuesByPropertyName[$propertyName])) {
						$valuesByPropertyName[$propertyName] = array();
					}
					if (!isset($maxScoresByPropertyNameAndValue[$propertyName])) {
						$maxScoresByPropertyNameAndValue[$propertyName] = array();
					}

					// Add the value for the given property, as we want to count
					// value frequencies later, we explicitly allow duplicates.
					$valuesByPropertyName[$propertyName][] = serialize($value);

					// As we have ordered our citations descending by score, the
					// first score found for a value is also the maximum score.
					if (!isset($maxScoresByPropertyNameAndValue[$propertyName][serialize($value)])) {
						$maxScoresByPropertyNameAndValue[$propertyName][serialize($value)] = $currentScore;
					}
				}
			}
		}

		// Step 2: Find out the values that were occur most frequently for each element
		//         and order these by score.

		foreach($valuesByPropertyName as $propertyName => $values) {
			// Count the occurrences of each value within the given element
			$valueFrequencies = array_count_values($values);

			// Order the most frequent values to the beginning of the array
			arsort($valueFrequencies);

			// Get the most frequent values (may be several if there are more than one
			// with the same frequency).
			$scoresOfMostFrequentValues = array();
			$previousValueFrequency = 0;
			foreach($valueFrequencies as $value => $valueFrequency) {
				// Only extract the most frequent values, jump out of the
				// loop when less frequent values start.
				if ($previousValueFrequency > $valueFrequency) break;
				$previousValueFrequency = $valueFrequency;

				$scoresOfMostFrequentValues[$value] =
						$maxScoresByPropertyNameAndValue[$propertyName][$value];
			}

			// Now we can order the most frequent values by score, starting
			// with the highest score.
			arsort($scoresOfMostFrequentValues);

			// Now get the first key which represents the value with the
			// highest frequency and the highest score.
			reset($scoresOfMostFrequentValues);
			$bestValue = unserialize(key($scoresOfMostFrequentValues));

			// Set the found "best" element value in the result citation.
			$statements = array($propertyName => $bestValue);
			$success = $targetDescription->setStatements($statements);
			assert($success);
		}

		// Calculate the average of all scores
		$overallScoreSum = 0;
		$overallScoreCount = 0;
		foreach ($scoredCitations as $currentScore => $citationsForCurrentScore) {
			$countCitationsForCurrentScore = count($citationsForCurrentScore);
			$overallScoreSum += $countCitationsForCurrentScore * $currentScore;
			$overallScoreCount += $countCitationsForCurrentScore;
		}
		$averageScore = $overallScoreSum / $overallScoreCount;

		// Get the max score (= the first key from scoredCitations
		// as these are sorted by score).
		reset($scoredCitations);
		$maxScore = key($scoredCitations);

		// Calculate the overall parse score as by weighing
		// the max score and the average score 50% each.
		// FIXME: This algorithm seems a bit arbitrary.
		$parseScore = ($maxScore + $averageScore) / 2;

		// Instantiate the target citation
		$targetCitation = new Citation();
		$targetCitation->injectMetadata($targetDescription);
		$targetCitation->setParseScore($parseScore);
		return $targetCitation;
	}
}
?>