package fr.gshz.hideandseek.domain.repository

import fr.gshz.hideandseek.domain.model.TrafficAlerts

interface TrafficAlertsRepository {
    suspend fun getTrafficAlerts(gameUuid: String): TrafficAlerts
}
