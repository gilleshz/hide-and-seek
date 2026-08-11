package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.domain.model.Round
import fr.gshz.hideandseek.domain.model.RoundStatus
import fr.gshz.hideandseek.domain.model.ScoreDeclaration
import fr.gshz.hideandseek.domain.model.TokenRefresh
import fr.gshz.hideandseek.domain.repository.RoundRepository
import kotlinx.coroutines.CompletableDeferred

class FakeRoundRepository : RoundRepository {
    var round: Round = Round(
        roundUuid = "round-1",
        status = RoundStatus.Lobby,
        hidingPeriodStartedAtMillis = null,
        hidingPeriodEndsAtMillis = null,
        seekingEndedAtMillis = null,
        hidingTimeSeconds = null,
    )
    var startResult: Result<Round>? = null
    var stopResult: Result<Round>? = null

    val startCalls = mutableListOf<String>()
    val stopCalls = mutableListOf<String>()
    val stopScores = mutableListOf<ScoreDeclaration?>()
    var lastStartHidingMinutes: Int? = null

    val getRoundCalls = mutableListOf<String>()

    override suspend fun getRound(roundUuid: String): Round {
        getRoundCalls += roundUuid
        return round
    }

    override suspend fun startRound(roundUuid: String, hidingPeriodMinutes: Int?): Round {
        startCalls += roundUuid
        lastStartHidingMinutes = hidingPeriodMinutes
        val started = startResult?.getOrThrow() ?: round.copy(status = RoundStatus.Hiding)
        round = started
        return started
    }

    override suspend fun stopRound(roundUuid: String, score: ScoreDeclaration?): Round {
        stopCalls += roundUuid
        stopScores += score
        val stopped = stopResult?.getOrThrow() ?: round.copy(status = RoundStatus.Ended, hidingTimeSeconds = 0)
        round = stopped
        return stopped
    }

    override suspend fun createRound(gameUuid: String): Round = round.copy(roundUuid = "round-2")

    var refreshSubscriberTokenResult: Result<TokenRefresh> =
        Result.success(TokenRefresh("fresh-token", listOf("fresh-topic")))
    var refreshSubscriberTokenGate: CompletableDeferred<Unit>? = null
    val refreshSubscriberTokenCalls = mutableListOf<String>()

    override suspend fun refreshSubscriberToken(roundUuid: String): TokenRefresh {
        refreshSubscriberTokenCalls += roundUuid
        refreshSubscriberTokenGate?.await()
        return refreshSubscriberTokenResult.getOrThrow()
    }
}
