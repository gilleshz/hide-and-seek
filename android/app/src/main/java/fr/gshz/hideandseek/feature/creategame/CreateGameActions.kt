package fr.gshz.hideandseek.feature.creategame

import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.repository.AreaInfo

data class CreateGameActions(
    val onNameChange: (String) -> Unit,
    val onSizeChange: (GameSize) -> Unit,
    val onEditionChange: (Edition) -> Unit,
    val onCreateClick: () -> Unit,
    val onAreaSearchQueryChange: (String) -> Unit = {},
    val onSearchAreasClick: () -> Unit = {},
    val onToggleArea: (AreaInfo) -> Unit = {},
    val onToggleTransitLineGroup: (TransitLineGroup) -> Unit = {},
    val onToggleTransitLineGroups: (List<TransitLineGroup>) -> Unit = {},
    val onToggleModeFilter: (String) -> Unit = {},
    val onToggleDiscoveryMode: (String) -> Unit = {},
    val onToggleAllDiscoveryModes: () -> Unit = {},
    val onShowDiscoveryModeDialog: () -> Unit = {},
    val onDismissDiscoveryModeDialog: () -> Unit = {},
    val onDiscoverOsmTransit: () -> Unit = {},
    val onRetryTransitPreview: () -> Unit = {},
    val onShowGtfsDialog: () -> Unit = {},
    val onDismissGtfsDialog: () -> Unit = {},
    val onGtfsUrlChange: (String) -> Unit = {},
    val onGtfsDialogModeChange: (GtfsDialogMode) -> Unit = {},
    val onUploadGtfsFromUrl: () -> Unit = {},
    val onUploadGtfsFromFile: (ByteArray, String) -> Unit = { _, _ -> },
    val onRemoveGtfsSource: (String) -> Unit = {},
    val onToggleGtfsRoute: (String, String) -> Unit = { _, _ -> },
)
