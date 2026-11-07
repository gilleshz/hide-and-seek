package fr.gshz.hideandseek.domain.model

enum class TrafficAlertSeverity(val wireValue: String) {
    Info("info"),
    Warning("warning"),
    Severe("severe"),
    ;

    companion object {
        fun fromWireValue(value: String): TrafficAlertSeverity =
            entries.find { it.wireValue == value } ?: Info
    }
}

data class TrafficAlert(
    val id: String,
    val channel: String,
    val severity: TrafficAlertSeverity,
    val title: String,
    val body: String,
    val lineRefs: List<String>,
    val routeIds: List<String>,
    val stopIds: List<String>,
    val validFrom: Long?,
    val validUntil: Long?,
    val source: String,
)

data class TrafficAlerts(
    val serverNow: Long,
    val alerts: List<TrafficAlert>,
)
