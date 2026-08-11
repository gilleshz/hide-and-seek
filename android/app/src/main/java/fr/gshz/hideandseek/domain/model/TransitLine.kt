package fr.gshz.hideandseek.domain.model

data class TransitLine(
    val osmId: String,
    val osmType: String,
    val ref: String,
    val name: String,
    val nameEn: String = "",
    val colour: String,
    val routeType: String,
    val network: String,
    val operator: String,
) {
    private val bestName: String get() = when {
        nameEn.isNotBlank() -> nameEn
        name.isNotBlank() -> name
        else -> ""
    }

    val label: String get() = when {
        ref.isNotBlank() && bestName.isNotBlank() -> "$ref: $bestName"
        ref.isNotBlank() -> ref
        bestName.isNotBlank() -> bestName
        else -> osmId
    }

    val displayName: String get() = label
}
