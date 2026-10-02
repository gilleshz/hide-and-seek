package fr.gshz.hideandseek.feature.question

import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.RulesVariant
import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Test

class QuestionPresetsTest {

    private fun distances(edition: Edition, size: GameSize, variant: RulesVariant): List<Double> =
        QuestionPresets.thermometerPresets(edition, size, variant).map { it.meters }

    @Test
    fun `official metric keeps the 5 km rung at every size`() {
        assertEquals(
            listOf(1000.0, 5000.0),
            distances(Edition.Metric, GameSize.Small, RulesVariant.Official),
        )
        assertEquals(
            listOf(1000.0, 5000.0, 15000.0),
            distances(Edition.Metric, GameSize.Medium, RulesVariant.Official),
        )
        assertEquals(
            listOf(1000.0, 5000.0, 15000.0, 75000.0),
            distances(Edition.Metric, GameSize.Large, RulesVariant.Official),
        )
    }

    @Test
    fun `official imperial keeps the 3 mi rung at every size`() {
        assertEquals(
            listOf(804.672, 4828.032),
            distances(Edition.Imperial, GameSize.Small, RulesVariant.Official),
        )
        assertEquals(
            listOf(804.672, 4828.032, 16093.44),
            distances(Edition.Imperial, GameSize.Medium, RulesVariant.Official),
        )
        assertEquals(
            listOf(804.672, 4828.032, 16093.44, 80467.2),
            distances(Edition.Imperial, GameSize.Large, RulesVariant.Official),
        )
    }

    @Test
    fun `compact metric replaces the 5 km rung with 2 km at every size`() {
        assertEquals(
            listOf(1000.0, 2000.0),
            distances(Edition.Metric, GameSize.Small, RulesVariant.Compact),
        )
        assertEquals(
            listOf(1000.0, 2000.0, 15000.0),
            distances(Edition.Metric, GameSize.Medium, RulesVariant.Compact),
        )
        assertEquals(
            listOf(1000.0, 2000.0, 15000.0, 75000.0),
            distances(Edition.Metric, GameSize.Large, RulesVariant.Compact),
        )
    }

    @Test
    fun `compact imperial replaces the 3 mi rung with 1 mi at every size`() {
        assertEquals(
            listOf(804.672, 1609.344),
            distances(Edition.Imperial, GameSize.Small, RulesVariant.Compact),
        )
        assertEquals(
            listOf(804.672, 1609.344, 16093.44),
            distances(Edition.Imperial, GameSize.Medium, RulesVariant.Compact),
        )
        assertEquals(
            listOf(804.672, 1609.344, 16093.44, 80467.2),
            distances(Edition.Imperial, GameSize.Large, RulesVariant.Compact),
        )
    }

    @Test
    fun `an absent or unknown wire value means the official rules`() {
        assertEquals(RulesVariant.Official, RulesVariant.fromWireValue(null))
        assertEquals(RulesVariant.Official, RulesVariant.fromWireValue("some-newer-variant"))
        assertEquals(RulesVariant.Compact, RulesVariant.fromWireValue("compact"))
    }
}
