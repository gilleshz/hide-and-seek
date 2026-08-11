package fr.gshz.hideandseek.data

import fr.gshz.hideandseek.core.data.ConnectionStore
import fr.gshz.hideandseek.core.model.NotConnectedException
import fr.gshz.hideandseek.data.mapper.toDomain
import fr.gshz.hideandseek.data.remote.HideAndSeekApi
import fr.gshz.hideandseek.data.remote.dto.RoundStartRequest
import fr.gshz.hideandseek.data.remote.dto.RoundStopRequest
import fr.gshz.hideandseek.domain.model.Round
import fr.gshz.hideandseek.domain.model.ScoreDeclaration
import fr.gshz.hideandseek.domain.model.TokenRefresh
import fr.gshz.hideandseek.domain.repository.RoundRepository
import javax.inject.Inject

class RoundRepositoryImpl @Inject constructor(
    private val api: HideAndSeekApi,
    private val connectionStore: ConnectionStore,
) : RoundRepository {

    override suspend fun getRound(roundUuid: String): Round =
        api.getRound(url = urlFor("/api/rounds/$roundUuid")).toDomain()

    override suspend fun startRound(roundUuid: String, hidingPeriodMinutes: Int?): Round =
        api.startRound(
            url = urlFor("/api/rounds/$roundUuid/start"),
            body = RoundStartRequest(hidingPeriodMinutes = hidingPeriodMinutes),
        ).toDomain()

    override suspend fun stopRound(roundUuid: String, score: ScoreDeclaration?): Round =
        api.stopRound(
            url = urlFor("/api/rounds/$roundUuid/stop"),
            body = RoundStopRequest(
                bonusMinutes = score?.bonusMinutes,
                bonusPercent = score?.bonusPercent,
                hidingSeconds = score?.hidingSeconds?.toInt(),
                // A declared score is the seekers reporting a catch; a bare stop aborts the round unscored.
                caught = score != null,
            ),
        ).toDomain()

    override suspend fun createRound(gameUuid: String): Round =
        api.createRound(url = urlFor("/api/games/$gameUuid/rounds")).toDomain()

    override suspend fun refreshSubscriberToken(roundUuid: String): TokenRefresh =
        api.refreshSubscriberToken(url = urlFor("/api/rounds/$roundUuid/subscriber-token")).toDomain()

    private suspend fun urlFor(path: String): String =
        (connectionStore.current() ?: throw NotConnectedException()).apiUrl.trimEnd('/') + path
}
