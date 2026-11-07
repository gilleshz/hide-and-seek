package fr.gshz.hideandseek.fake

import fr.gshz.hideandseek.domain.model.TrafficAlerts
import fr.gshz.hideandseek.domain.repository.TrafficAlertsRepository

class FakeTrafficAlertsRepository : TrafficAlertsRepository {
    var result: Result<TrafficAlerts> = Result.success(TrafficAlerts(serverNow = 0, alerts = emptyList()))
    val calls = mutableListOf<String>()

    override suspend fun getTrafficAlerts(gameUuid: String): TrafficAlerts {
        calls += gameUuid
        return result.getOrThrow()
    }
}
