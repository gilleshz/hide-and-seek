package fr.gshz.hideandseek.domain.model

import org.junit.jupiter.api.Assertions.assertEquals
import org.junit.jupiter.api.Test

class ScoreDeclarationTest {

    @Test
    fun `flat minutes become seconds`() {
        assertEquals(900L, ScoreDeclaration(bonusMinutes = 15).bonusSecondsFor(HOUR))
    }

    @Test
    fun `a percentage counts off the hiding time and not off the flat bonus`() {
        val score = ScoreDeclaration(bonusMinutes = 15, bonusPercent = 20)

        assertEquals(720L, score.percentSecondsFor(HOUR))
        assertEquals(900L + 720L, score.bonusSecondsFor(HOUR))
    }

    @Test
    fun `several percentages add up instead of compounding`() {
        val separately = ScoreDeclaration(bonusPercent = 10).percentSecondsFor(HOUR) +
            ScoreDeclaration(bonusPercent = 25).percentSecondsFor(HOUR)

        assertEquals(separately, ScoreDeclaration(bonusPercent = 35).percentSecondsFor(HOUR))
    }

    @Test
    fun `the total is the hiding time plus every bonus`() {
        val score = ScoreDeclaration(bonusMinutes = 15, bonusPercent = 20)

        assertEquals(HOUR + 900L + 720L, score.totalSecondsFor(HOUR))
    }

    private companion object {
        const val HOUR = 3600L
    }
}
