package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.Round
import fr.gshz.hideandseek.domain.model.ScoreDeclaration
import fr.gshz.hideandseek.domain.model.TokenRefresh

interface RoundRepository {
    suspend fun getRound(roundUuid: String): Round
    suspend fun startRound(roundUuid: String, hidingPeriodMinutes: Int? = null): Round
    suspend fun stopRound(roundUuid: String, score: ScoreDeclaration? = null): Round
    suspend fun createRound(gameUuid: String): Round
    suspend fun refreshSubscriberToken(roundUuid: String): TokenRefresh
}
