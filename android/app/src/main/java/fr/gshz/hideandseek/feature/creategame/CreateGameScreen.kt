package fr.gshz.hideandseek.feature.creategame

import android.content.ContentResolver
import android.content.Context
import android.net.Uri
import android.provider.OpenableColumns
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.runtime.rememberCoroutineScope
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.AddLocationAlt
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Public
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Straighten
import androidx.compose.material3.Button
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.SegmentedButton
import androidx.compose.material3.SegmentedButtonDefaults
import androidx.compose.material3.SingleChoiceSegmentedButtonRow
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.ErrorText
import fr.gshz.hideandseek.core.util.StreamLimitExceededException
import fr.gshz.hideandseek.core.ui.SectionHeader
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.repository.AreaInfo

@Composable
fun CreateGameScreen(
    onGameCreated: (gameUuid: String) -> Unit,
    onNeedAccount: (errorKey: String?) -> Unit,
    viewModel: CreateGameViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()
    val context = LocalContext.current
    val coroutineScope = rememberCoroutineScope()

    val filePickerLauncher = rememberGtfsFilePicker(
        context = context,
        coroutineScope = coroutineScope,
        viewModel = viewModel,
    )

    LaunchedEffect(uiState.createdGameUuid) {
        uiState.createdGameUuid?.let(onGameCreated)
    }

    LaunchedEffect(uiState.needsAccount) {
        if (uiState.needsAccount) onNeedAccount(uiState.needAccountErrorKey)
    }

    CreateGameContent(
        uiState = uiState,
        actions = CreateGameActions(
            onNameChange = viewModel::onNameChange,
            onSizeChange = viewModel::onSizeChange,
            onEditionChange = viewModel::onEditionChange,
            onCreateClick = viewModel::createGame,
            onAreaSearchQueryChange = viewModel::onAreaSearchQueryChange,
            onSearchAreasClick = viewModel::searchAreas,
            onToggleArea = viewModel::toggleArea,
            onToggleTransitLineGroup = viewModel::toggleTransitLineGroup,
            onToggleTransitLineGroups = viewModel::toggleTransitLineGroups,
            onToggleModeFilter = viewModel::toggleModeFilter,
            onToggleDiscoveryMode = viewModel::toggleDiscoveryMode,
            onToggleAllDiscoveryModes = viewModel::toggleAllDiscoveryModes,
            onShowDiscoveryModeDialog = viewModel::showDiscoveryModeDialog,
            onDismissDiscoveryModeDialog = viewModel::dismissDiscoveryModeDialog,
            onDiscoverOsmTransit = viewModel::discoverOsmTransit,
            onRetryTransitPreview = viewModel::retryTransitPreview,
            onShowGtfsDialog = viewModel::showGtfsDialog,
            onDismissGtfsDialog = viewModel::dismissGtfsDialog,
            onGtfsUrlChange = viewModel::onGtfsUrlChange,
            onGtfsDialogModeChange = viewModel::onGtfsDialogModeChange,
            onUploadGtfsFromUrl = viewModel::uploadGtfsFromUrl,
            onUploadGtfsFromFile = viewModel::uploadGtfsFromFile,
            onRemoveGtfsSource = viewModel::removeGtfsSource,
            onToggleGtfsRoute = viewModel::toggleGtfsRoute,
        ),
        onChooseGtfsFile = { filePickerLauncher.launch("application/zip") },
    )
}

@Composable
internal fun CreateGameContent(
    uiState: CreateGameUiState,
    actions: CreateGameActions,
    modifier: Modifier = Modifier,
    onChooseGtfsFile: (() -> Unit)? = null,
) {
    Scaffold(
        modifier = modifier,
        bottomBar = {
            Surface(
                tonalElevation = 6.dp,
                shadowElevation = 8.dp,
                modifier = Modifier.navigationBarsPadding(),
            ) {
                CreateGameButton(
                    isLoading = uiState.isLoading,
                    onClick = actions.onCreateClick,
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(Spacing.md),
                )
            }
        },
    ) { innerPadding ->
        CreateGameBody(innerPadding, uiState, actions)
    }

    GtfsDialogIfShown(uiState = uiState, actions = actions, onChooseFile = onChooseGtfsFile)
}

@Composable
private fun GtfsDialogIfShown(
    uiState: CreateGameUiState,
    actions: CreateGameActions,
    onChooseFile: (() -> Unit)?,
) {
    if (uiState.showGtfsDialog) {
        GtfsUploadDialog(
            mode = uiState.gtfsDialogMode,
            urlInput = uiState.gtfsUrlInput,
            isUploading = uiState.gtfsUploading,
            error = uiState.gtfsError,
            onModeChange = actions.onGtfsDialogModeChange,
            onUrlChange = actions.onGtfsUrlChange,
            onUploadUrl = actions.onUploadGtfsFromUrl,
            onChooseFile = { onChooseFile?.invoke() },
            onDismiss = actions.onDismissGtfsDialog,
        )
    }
}

@Composable
private fun CreateGameBody(
    innerPadding: androidx.compose.foundation.layout.PaddingValues,
    uiState: CreateGameUiState,
    actions: CreateGameActions,
) {
    Column(
        modifier = Modifier
            .fillMaxSize()
            .padding(innerPadding)
            .verticalScroll(rememberScrollState())
            .padding(horizontal = Spacing.lg, vertical = Spacing.md),
        verticalArrangement = Arrangement.spacedBy(Spacing.lg),
    ) {
        ScreenTitle()
        BoundaryPreviewMap(
            geoJson = uiState.boundaryGeoJson,
            modifier = Modifier.fillMaxWidth().height(200.dp),
            transitGeoJson = uiState.transitPreviewGeoJson,
        )
        TransitPreviewSection(uiState, actions)
        GameConfigSection(uiState, actions)
        AreaSearchSection(uiState, actions)
        TransitLineSection(uiState, actions)
        ErrorText(uiState.error, errorKey = uiState.errorKey, errorArgs = uiState.errorArgs)
    }
}

@Composable
private fun TransitLineSection(uiState: CreateGameUiState, actions: CreateGameActions) {
    TransitLinePicker(
        groups = uiState.transitLineGroups,
        availableModes = uiState.availableModes,
        selectedModeFilters = uiState.transitModeFilter,
        selectedIds = uiState.selectedTransitLines,
        onToggleGroup = actions.onToggleTransitLineGroup,
        onToggleGroups = actions.onToggleTransitLineGroups,
        onToggleModeFilter = actions.onToggleModeFilter,
        gtfsSources = uiState.gtfsSources,
        uniqueGtfsRoutes = uiState.uniqueGtfsRoutes,
        selectedGtfsRouteKeys = uiState.selectedGtfsRouteKeys,
        onToggleGtfsRoute = actions.onToggleGtfsRoute,
        onShowGtfsDialog = actions.onShowGtfsDialog,
        onRemoveGtfsSource = actions.onRemoveGtfsSource,
        hasDiscoveredTransit = uiState.hasDiscoveredTransit,
        isDiscoveringTransit = uiState.isDiscoveringTransit,
        onDiscoverOsmTransit = actions.onDiscoverOsmTransit,
        hasSelectedAreas = uiState.selectedAreas.isNotEmpty(),
        discoveryModeSelection = uiState.discoveryModeSelection,
        onToggleDiscoveryMode = actions.onToggleDiscoveryMode,
        onToggleAllDiscoveryModes = actions.onToggleAllDiscoveryModes,
        showDiscoveryModeDialog = uiState.showDiscoveryModeDialog,
        onShowDiscoveryModeDialog = actions.onShowDiscoveryModeDialog,
        onDismissDiscoveryModeDialog = actions.onDismissDiscoveryModeDialog,
        discoveryError = uiState.discoveryError,
    )
}

@Composable
private fun GameFormFields(
    name: String,
    onNameChange: (String) -> Unit,
) {
    OutlinedTextField(
        value = name,
        onValueChange = onNameChange,
        label = { Text(stringResource(R.string.create_game_name_label)) },
        modifier = Modifier.fillMaxWidth(),
        singleLine = true,
    )
}

@Composable
private fun GameConfigSection(uiState: CreateGameUiState, actions: CreateGameActions) {
    GameFormFields(uiState.name, actions.onNameChange)
    SizeSelector(uiState.size, actions.onSizeChange)
    EditionSelector(uiState.edition, actions.onEditionChange)
}

@Composable
private fun ScreenTitle(modifier: Modifier = Modifier) {
    Row(
        modifier = modifier,
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        Icon(
            Icons.Filled.AddLocationAlt,
            contentDescription = null,
            tint = MaterialTheme.colorScheme.primary,
        )
        Text(
            text = stringResource(R.string.create_game_title),
            style = MaterialTheme.typography.headlineSmall,
        )
    }
}

@Composable
private fun CreateGameButton(isLoading: Boolean, onClick: () -> Unit, modifier: Modifier = Modifier) {
    Button(onClick = onClick, enabled = !isLoading, modifier = modifier) {
        if (isLoading) {
            CircularProgressIndicator(
                modifier = Modifier.size(20.dp),
                color = MaterialTheme.colorScheme.onPrimary,
                strokeWidth = 2.dp,
            )
        } else {
            Icon(Icons.Filled.Check, contentDescription = null, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(Spacing.sm))
            Text(
                stringResource(R.string.create_game_button),
                style = MaterialTheme.typography.titleMedium,
            )
        }
    }
}

@Composable
private fun SizeSelector(selected: GameSize, onSelect: (GameSize) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        SectionHeader(text = stringResource(R.string.create_game_size_label), icon = Icons.Filled.Straighten)
        SegmentedOptionRow(GameSize.entries, selected, onSelect, { it.name })
    }
}

@Composable
private fun EditionSelector(selected: Edition, onSelect: (Edition) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        SectionHeader(text = stringResource(R.string.create_game_edition_label), icon = Icons.Filled.Public)
        SegmentedOptionRow(Edition.entries, selected, onSelect, { it.name })
    }
}

@Composable
private fun <T> SegmentedOptionRow(
    options: List<T>,
    selected: T,
    onSelect: (T) -> Unit,
    label: (T) -> String,
) {
    SingleChoiceSegmentedButtonRow(modifier = Modifier.fillMaxWidth()) {
        options.forEachIndexed { index, option ->
            SegmentedButton(
                selected = selected == option,
                onClick = { onSelect(option) },
                shape = SegmentedButtonDefaults.itemShape(index = index, count = options.size),
            ) { Text(label(option)) }
        }
    }
}

@androidx.compose.ui.tooling.preview.Preview(showBackground = true)
@Composable
private fun CreateGameContentPreview() {
    AppTheme {
        CreateGameContent(
            uiState = CreateGameUiState(name = "Berlin"),
            actions = CreateGameActions(
                onNameChange = {},
                onSizeChange = {},
                onEditionChange = {},
                onCreateClick = {},
            ),
        )
    }
}

@Composable
private fun rememberGtfsFilePicker(
    context: Context,
    coroutineScope: CoroutineScope,
    viewModel: CreateGameViewModel,
) = run {
    val gtfsReadError = stringResource(R.string.gtfs_read_error)
    val gtfsTooLargeError = stringResource(R.string.gtfs_too_large_error)
    rememberLauncherForActivityResult(
        contract = ActivityResultContracts.GetContent(),
    ) { uri: Uri? ->
        if (uri == null) return@rememberLauncherForActivityResult
        coroutineScope.launch(Dispatchers.IO) {
            val fileName = context.contentResolver.query(uri, null, null, null, null)?.use { cursor ->
                val nameIndex = cursor.getColumnIndex(OpenableColumns.DISPLAY_NAME)
                if (cursor.moveToFirst() && nameIndex >= 0) cursor.getString(nameIndex) else null
            } ?: uri.lastPathSegment ?: "gtfs.zip"
            val bytes = try {
                readGtfsFile(context.contentResolver, uri)
            } catch (_: StreamLimitExceededException) {
                viewModel.onGtfsFileError(gtfsTooLargeError)
                return@launch
            }
            if (bytes != null) {
                viewModel.uploadGtfsFromFile(bytes, fileName)
            } else {
                viewModel.onGtfsFileError(gtfsReadError)
            }
        }
    }
}
