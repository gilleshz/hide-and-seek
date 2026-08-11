@file:Suppress("TooManyFunctions")
package fr.gshz.hideandseek.feature.map

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExposedDropdownMenuBox
import androidx.compose.material3.ExposedDropdownMenuDefaults
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.ErrorText
import fr.gshz.hideandseek.core.ui.PresetChip
import fr.gshz.hideandseek.core.ui.formatDistance
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.model.AskedQuestion
import fr.gshz.hideandseek.domain.model.Edition
import fr.gshz.hideandseek.domain.model.FeatureType
import fr.gshz.hideandseek.domain.model.GameSize
import fr.gshz.hideandseek.domain.model.PhotoTarget
import fr.gshz.hideandseek.domain.model.QuestionCategory
import fr.gshz.hideandseek.domain.model.TransitLine
import fr.gshz.hideandseek.domain.model.cardEconomy
import fr.gshz.hideandseek.feature.question.CUSTOM_RADAR_SENTINEL
import fr.gshz.hideandseek.feature.question.QuestionCategoryChips
import fr.gshz.hideandseek.feature.question.QuestionPresets
import fr.gshz.hideandseek.feature.question.availableFeatureTypes
import fr.gshz.hideandseek.feature.question.tentaclesRangeMeters
import kotlin.math.roundToInt

private const val PRESETS_PER_ROW = 4
private const val AREA_KM2_LARGE_THRESHOLD = 1000.0
private const val AREA_KM2_SMALL_THRESHOLD = 1.0
private const val NARROWING_PCT_MULTIPLIER = 100.0
private const val NARROWING_PCT_MIN = 0
private const val NARROWING_PCT_MAX = 100
private const val SHEET_MAX_HEIGHT_FRACTION = 0.5f

@Composable
internal fun SimulationSheet(
    state: SimulationState,
    edition: Edition,
    gameSize: GameSize,
    actions: SimulationActions,
    modifier: Modifier = Modifier,
    askedQuestions: List<AskedQuestion> = emptyList(),
) {
    val isPreview = state.mode == QuestionSheetMode.Preview
    val maxSheetHeight = (LocalConfiguration.current.screenHeightDp * SHEET_MAX_HEIGHT_FRACTION).dp
    var showRepeatDialog by remember { mutableStateOf(false) }
    val repeatCount = repeatCountFor(state, askedQuestions)
    RepeatConfirmDialog(showRepeatDialog, state.category, repeatCount, actions.onAsk) { showRepeatDialog = false }
    Surface(
        modifier = modifier.heightIn(max = maxSheetHeight),
        shape = MaterialTheme.shapes.large,
        tonalElevation = 6.dp,
        shadowElevation = 4.dp,
    ) {
        Column(modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm)) {
            Text(
                text = if (isPreview) stringResource(R.string.sim_title)
                    else stringResource(R.string.sim_ask_title),
                style = MaterialTheme.typography.titleMedium,
            )
            ErrorText(state.error, errorKey = state.errorKey, errorArgs = state.errorArgs)
            SimSheetScrollContent(
                state = state,
                edition = edition,
                gameSize = gameSize,
                actions = actions,
                modifier = Modifier.weight(1f, fill = false),
                askedQuestions = askedQuestions,
            )
            if (isPreview && state.outstandingQuestion == null) {
                Spacer(modifier = Modifier.height(Spacing.sm))
                NarrowingReadout(state.currentAreaKm2, state.projectedAreaKm2)
            }
            Spacer(modifier = Modifier.height(Spacing.sm))
            SimActionButtons(state, actions, repeatCount) { showRepeatDialog = true }
        }
    }
}

@Composable
private fun SimSheetScrollContent(
    state: SimulationState,
    edition: Edition,
    gameSize: GameSize,
    actions: SimulationActions,
    modifier: Modifier = Modifier,
    askedQuestions: List<AskedQuestion> = emptyList(),
) {
    val isPreview = state.mode == QuestionSheetMode.Preview
    Column(
        modifier = modifier.verticalScroll(rememberScrollState()),
        verticalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        Spacer(modifier = Modifier.height(Spacing.xs))
        if (state.locationPermissionMissing) {
            Text(
                text = stringResource(R.string.question_permission_required),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.error,
            )
        }
        if (!isPreview && state.askingBlocked) {
            Text(
                text = stringResource(R.string.question_hiding_period),
                style = MaterialTheme.typography.bodySmall,
                color = MaterialTheme.colorScheme.error,
            )
        }
        state.outstandingQuestion?.let { outstanding ->
            OutstandingQuestionCard(outstanding, state, actions, edition)
        }
        QuestionCategoryChips(state.category, gameSize, actions.onSetCategory)
        SimCategoryContent(state, edition, gameSize, actions, askedQuestions)
        if (state.category != QuestionCategory.Photos) {
            PreviewToggle(isPreview, actions.onTogglePreviewMode)
        }
    }
}

@Composable
private fun SimCategoryContent(
    state: SimulationState,
    edition: Edition,
    gameSize: GameSize,
    actions: SimulationActions,
    askedQuestions: List<AskedQuestion>,
) {
    when (state.category) {
        QuestionCategory.Radar -> RadarPresetRows(state, edition, actions, askedQuestions)
        QuestionCategory.Thermometer -> ThermometerPresetRow(state, edition, gameSize, actions, askedQuestions)
        QuestionCategory.Measuring -> MeasuringContent(state, gameSize, actions, askedQuestions)
        QuestionCategory.Matching -> MatchingContent(state, gameSize, actions, askedQuestions)
        QuestionCategory.Tentacles -> TentaclesContent(state, gameSize, actions, askedQuestions)
        QuestionCategory.Photos -> PhotosContent(state, edition, gameSize, actions, askedQuestions)
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun PhotosContent(
    state: SimulationState,
    edition: Edition,
    gameSize: GameSize,
    actions: SimulationActions,
    askedQuestions: List<AskedQuestion>,
) {
    var expanded by remember { mutableStateOf(false) }
    val targets = PhotoTarget.entries.filter { it.isAvailableFor(gameSize) }
    ExposedDropdownMenuBox(expanded = expanded, onExpandedChange = { expanded = it }) {
        OutlinedTextField(
            value = state.photoTarget?.let { stringResource(it.labelRes(edition)) }.orEmpty(),
            onValueChange = {},
            readOnly = true,
            label = { Text(stringResource(R.string.sim_pick_photo_target)) },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
            modifier = Modifier
                .menuAnchor()
                .fillMaxWidth(),
        )
        ExposedDropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            targets.forEach { target ->
                val asked = askedQuestions.any {
                    it.category == QuestionCategory.Photos && it.photoTarget == target
                }
                DropdownMenuItem(
                    text = {
                        Text(
                            text = stringResource(target.labelRes(edition)),
                            color = if (asked) MaterialTheme.colorScheme.error
                                else MaterialTheme.colorScheme.onSurface,
                        )
                    },
                    onClick = {
                        actions.onSetPhotoTarget(target)
                        expanded = false
                    },
                    trailingIcon = if (target == state.photoTarget) {
                        { Icon(Icons.Default.Check, contentDescription = null) }
                    } else {
                        null
                    },
                    contentPadding = ExposedDropdownMenuDefaults.ItemContentPadding,
                )
            }
        }
    }
}

private data class SimOption(
    val labelRes: Int,
    val selected: Boolean,
    val onClick: () -> Unit,
    val enabled: Boolean = true,
    val isRepeat: Boolean = false,
)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun OptionDropdown(label: String, options: List<SimOption>) {
    var expanded by remember { mutableStateOf(false) }
    val selectedLabel = options.firstOrNull { it.selected }?.let { stringResource(it.labelRes) }.orEmpty()
    ExposedDropdownMenuBox(expanded = expanded, onExpandedChange = { expanded = it }) {
        OutlinedTextField(
            value = selectedLabel,
            onValueChange = {},
            readOnly = true,
            label = { Text(label) },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
            modifier = Modifier
                .menuAnchor()
                .fillMaxWidth(),
        )
        ExposedDropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            options.forEach { option ->
                DropdownMenuItem(
                    text = {
                        Text(
                            text = stringResource(option.labelRes),
                            color = if (option.isRepeat) MaterialTheme.colorScheme.error
                                else MaterialTheme.colorScheme.onSurface,
                        )
                    },
                    onClick = {
                        option.onClick()
                        expanded = false
                    },
                    enabled = option.enabled,
                    trailingIcon = if (option.selected) {
                        { Icon(Icons.Default.Check, contentDescription = null) }
                    } else {
                        null
                    },
                    contentPadding = ExposedDropdownMenuDefaults.ItemContentPadding,
                )
            }
        }
    }
}

private fun measuringOptions(
    state: SimulationState,
    gameSize: GameSize,
    actions: SimulationActions,
    askedQuestions: List<AskedQuestion>,
): List<SimOption> = buildList {
    availableFeatureTypes(QuestionCategory.Measuring, gameSize).forEach { ft ->
        val disabled = ft == FeatureType.Coastline
        val repeat = askedQuestions.count {
            it.category == QuestionCategory.Measuring && it.featureType?.wireValue == ft.wireValue
        } > 0
        add(
            SimOption(
                labelRes = ft.labelRes,
                selected = state.featureType == ft.wireValue,
                onClick = { if (!disabled) actions.onSetFeatureType(ft.wireValue) },
                enabled = !disabled,
                isRepeat = repeat,
            ),
        )
    }
    add(
        SimOption(
            labelRes = R.string.sim_sea_level_option,
            selected = state.seaLevelSelected,
            onClick = actions.onSelectSeaLevelOption,
        ),
    )
}

private fun matchingOptions(
    state: SimulationState,
    gameSize: GameSize,
    actions: SimulationActions,
    askedQuestions: List<AskedQuestion>,
): List<SimOption> = buildList {
    availableFeatureTypes(QuestionCategory.Matching, gameSize).forEach { ft ->
        val repeat = askedQuestions.count {
            it.category == QuestionCategory.Matching && it.featureType?.wireValue == ft.wireValue
        } > 0
        add(
            SimOption(
                labelRes = ft.labelRes,
                selected = state.featureType == ft.wireValue,
                onClick = { actions.onSetFeatureType(ft.wireValue) },
                isRepeat = repeat,
            ),
        )
    }
    if (state.availableTransitLines.isNotEmpty()) {
        add(
            SimOption(
                labelRes = R.string.sim_transit_line_option,
                selected = state.transitLineSelected,
                onClick = actions.onSelectTransitLineOption,
            ),
        )
    }
    add(
        SimOption(
            labelRes = R.string.sim_station_name_length_option,
            selected = state.stationNameLengthSelected,
            onClick = actions.onSelectStationNameLengthOption,
        ),
    )
}

@Composable
private fun RadarPresetRows(
    state: SimulationState,
    edition: Edition,
    actions: SimulationActions,
    askedQuestions: List<AskedQuestion>,
) {
    val instruction = if (state.mode == QuestionSheetMode.Ask) stringResource(R.string.sim_using_gps)
        else if (state.seeker == null) stringResource(R.string.sim_place_start)
        else stringResource(R.string.sim_pick_radius)
    Text(instruction, style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant)
    val presets = QuestionPresets.radarPresets(edition)
    val displaySelectedRadius = if (state.isCustomRadius) CUSTOM_RADAR_SENTINEL.toInt() else state.radiusMeters
    presets.chunked(PRESETS_PER_ROW).forEach { chunk ->
        PresetRow(
            items = chunk.map { it.labelRes to it.meters },
            selectedValue = displaySelectedRadius,
            onSelect = actions.onSetRadius,
            isRepeatCheck = { meters ->
                if (meters == CUSTOM_RADAR_SENTINEL) {
                    askedQuestions.count { it.category == QuestionCategory.Radar && it.isCustomRadius } > 0
                } else {
                    askedQuestions.count { q ->
                        q.category == QuestionCategory.Radar &&
                            q.radiusMeters?.roundToInt() == meters.roundToInt()
                    } > 0
                }
            },
        )
    }
    if (state.isCustomRadius) {
        val suffix = if (edition == Edition.Imperial)
            stringResource(R.string.unit_miles_short)
        else
            stringResource(R.string.unit_kilometers_short)
        OutlinedTextField(
            value = state.customRadiusText,
            onValueChange = actions.onCustomRadiusChange,
            label = { Text(stringResource(R.string.sim_custom_radius_hint)) },
            suffix = { Text(suffix) },
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )
    }
    if (state.mode == QuestionSheetMode.Preview) {
        SimAnswerToggle(
            option1 = R.string.sim_inside to SimAnswer.Inside,
            option2 = R.string.sim_outside to SimAnswer.Outside,
            selected = state.answer,
            onSelect = actions.onSetAnswer,
        )
    }
}

@Composable
private fun ThermometerPresetRow(
    state: SimulationState,
    edition: Edition,
    gameSize: GameSize,
    actions: SimulationActions,
    askedQuestions: List<AskedQuestion>,
) {
    val instruction = when {
        state.travelingThermometer != null -> stringResource(R.string.question_thermometer_awaiting_arrival)
        state.mode == QuestionSheetMode.Ask -> stringResource(R.string.sim_using_gps)
        state.seeker == null -> stringResource(R.string.sim_place_start)
        state.end == null -> stringResource(R.string.sim_place_end)
        else -> stringResource(R.string.sim_pick_distance)
    }
    Text(instruction, style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant)
    when {
        state.travelingThermometer != null -> Unit
        state.mode == QuestionSheetMode.Ask -> {
            ThermometerDistancePresets(state, edition, gameSize, actions, askedQuestions)
            Button(
                onClick = actions.onStartThermometer,
                enabled = state.distanceMeters != null && !state.isSubmitting &&
                    state.outstandingQuestion == null && !state.askingBlocked,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.question_thermometer_start_button))
            }
        }
        else -> {
            ThermometerDistancePresets(state, edition, gameSize, actions, askedQuestions)
            SimAnswerToggle(
                option1 = R.string.sim_hotter to SimAnswer.Hotter,
                option2 = R.string.sim_colder to SimAnswer.Colder,
                selected = state.answer,
                onSelect = actions.onSetAnswer,
            )
        }
    }
}

@Composable
private fun ThermometerDistancePresets(
    state: SimulationState,
    edition: Edition,
    gameSize: GameSize,
    actions: SimulationActions,
    askedQuestions: List<AskedQuestion>,
) {
    val presets = QuestionPresets.thermometerPresets(edition, gameSize)
    if (presets.isNotEmpty()) {
        PresetRow(
            items = presets.map { it.labelRes to it.meters },
            selectedValue = state.distanceMeters,
            onSelect = actions.onSetDistance,
            isRepeatCheck = { meters ->
                askedQuestions.count { it.category == QuestionCategory.Thermometer && it.distanceMeters == meters } > 0
            },
        )
    }
}

@Composable
private fun PresetRow(
    items: List<Pair<Int, Double>>,
    selectedValue: Int?,
    onSelect: (Int?) -> Unit,
    isRepeatCheck: ((Double) -> Boolean)? = null,
) {
    Row(
        horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
        modifier = Modifier.fillMaxWidth(),
    ) {
        items.forEach { (labelRes, meters) ->
            val metersInt = meters.roundToInt()
            PresetChip(
                label = stringResource(labelRes),
                selected = selectedValue == metersInt,
                onClick = { onSelect(metersInt) },
                modifier = Modifier.weight(1f),
                isRepeat = isRepeatCheck?.invoke(meters) == true,
            )
        }
    }
}

@Composable
private fun SimAnswerToggle(
    option1: Pair<Int, SimAnswer>,
    option2: Pair<Int, SimAnswer>,
    selected: SimAnswer?,
    onSelect: (SimAnswer) -> Unit,
) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        SimAnswerButton(option1, selected == option1.second, onSelect, Modifier.weight(1f))
        SimAnswerButton(option2, selected == option2.second, onSelect, Modifier.weight(1f))
    }
}

@Composable
private fun SimAnswerButton(
    option: Pair<Int, SimAnswer>,
    selected: Boolean,
    onSelect: (SimAnswer) -> Unit,
    modifier: Modifier = Modifier,
) {
    val label = stringResource(option.first)
    val onClick = { onSelect(option.second) }
    if (selected) {
        Button(onClick = onClick, modifier = modifier) { Text(label) }
    } else {
        OutlinedButton(onClick = onClick, modifier = modifier) { Text(label) }
    }
}

@Composable
private fun NarrowingReadout(currentKm2: Double?, projectedKm2: Double?) {
    val narrowing = simNarrowing(currentKm2, projectedKm2) ?: return
    val currentLabel = areaLabel(narrowing.currentKm2)
    val projectedLabel = areaLabel(narrowing.projectedKm2)
    Text(
        text = stringResource(
            R.string.sim_narrowed,
            narrowing.pct,
            currentLabel,
            projectedLabel,
        ),
        style = MaterialTheme.typography.bodyMedium,
        fontWeight = FontWeight.Medium,
        modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
private fun SimActionButtons(
    state: SimulationState,
    actions: SimulationActions,
    repeatCount: Int,
    onRepeatConfirmNeeded: () -> Unit,
) {
    Row(horizontalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        OutlinedButton(onClick = actions.onDismiss, modifier = Modifier.weight(1f)) {
            Text(stringResource(R.string.sim_close_button))
        }
        Button(
            onClick = if (repeatCount > 1) onRepeatConfirmNeeded else actions.onAsk,
            enabled = simCanAsk(state),
            modifier = Modifier.weight(1f),
        ) {
            Text(stringResource(R.string.sim_ask))
        }
    }
}

@Composable
private fun MeasuringContent(
    state: SimulationState,
    gameSize: GameSize,
    actions: SimulationActions,
    askedQuestions: List<AskedQuestion>,
) {
    val instruction = if (state.mode == QuestionSheetMode.Ask) stringResource(R.string.sim_using_gps)
        else if (state.seeker == null) stringResource(R.string.sim_place_start)
        else stringResource(R.string.sim_pick_feature)
    Text(
        instruction,
        style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
    )
    OptionDropdown(
        stringResource(R.string.sim_pick_feature),
        measuringOptions(state, gameSize, actions, askedQuestions),
    )
    if (state.mode == QuestionSheetMode.Preview) {
        SimAnswerToggle(
            option1 = R.string.sim_closer to SimAnswer.Closer,
            option2 = R.string.sim_further to SimAnswer.Further,
            selected = state.answer,
            onSelect = actions.onSetAnswer,
        )
    }
}

@Composable
private fun MatchingContent(
    state: SimulationState,
    gameSize: GameSize,
    actions: SimulationActions,
    askedQuestions: List<AskedQuestion>,
) {
    val instruction = if (state.mode == QuestionSheetMode.Ask) stringResource(R.string.sim_using_gps)
        else if (state.seeker == null) stringResource(R.string.sim_place_start)
        else if (state.featureType == null && !state.transitLineSelected && !state.stationNameLengthSelected)
            stringResource(R.string.sim_pick_feature)
        else stringResource(R.string.sim_pick_poi)
    Text(
        instruction,
        style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
    )
    OptionDropdown(
        stringResource(R.string.sim_pick_feature),
        matchingOptions(state, gameSize, actions, askedQuestions),
    )
    if (state.transitLineSelected) {
        TransitLineDropdown(state.availableTransitLines, state.selectedTransitLine, actions.onSetTransitLine)
    }
    if (state.mode == QuestionSheetMode.Preview) {
        SimAnswerToggle(
            option1 = R.string.sim_same to SimAnswer.Same,
            option2 = R.string.sim_different to SimAnswer.Different,
            selected = state.answer,
            onSelect = actions.onSetAnswer,
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun TransitLineDropdown(
    lines: List<TransitLine>,
    selected: TransitLine?,
    onSelect: (TransitLine) -> Unit,
) {
    var expanded by remember { mutableStateOf(false) }
    ExposedDropdownMenuBox(expanded = expanded, onExpandedChange = { expanded = it }) {
        OutlinedTextField(
            value = selected?.label.orEmpty(),
            onValueChange = {},
            readOnly = true,
            label = { Text(stringResource(R.string.sim_pick_transit_line)) },
            trailingIcon = { ExposedDropdownMenuDefaults.TrailingIcon(expanded = expanded) },
            modifier = Modifier
                .menuAnchor()
                .fillMaxWidth(),
        )
        ExposedDropdownMenu(expanded = expanded, onDismissRequest = { expanded = false }) {
            lines.forEach { line ->
                DropdownMenuItem(
                    text = { Text(line.label) },
                    onClick = {
                        onSelect(line)
                        expanded = false
                    },
                    trailingIcon = if (line == selected) {
                        { Icon(Icons.Default.Check, contentDescription = null) }
                    } else {
                        null
                    },
                    contentPadding = ExposedDropdownMenuDefaults.ItemContentPadding,
                )
            }
        }
    }
}

@Composable
private fun TentaclesContent(
    state: SimulationState,
    gameSize: GameSize,
    actions: SimulationActions,
    askedQuestions: List<AskedQuestion>,
) {
    if (gameSize == GameSize.Small) return
    val instruction = when {
        state.mode == QuestionSheetMode.Ask -> stringResource(R.string.sim_using_gps)
        state.seeker == null -> stringResource(R.string.sim_place_start)
        state.withinMeters == null -> stringResource(R.string.sim_pick_range)
        state.featureType == null -> stringResource(R.string.sim_pick_feature)
        else -> stringResource(R.string.sim_pick_poi)
    }
    Text(
        instruction,
        style = MaterialTheme.typography.bodySmall,
        color = MaterialTheme.colorScheme.onSurfaceVariant,
    )
    val rangeMeters = tentaclesRangeMeters(gameSize).toInt()
    Row(horizontalArrangement = Arrangement.spacedBy(Spacing.xs)) {
        val rangeRepeatCount = askedQuestions.count {
            it.category == QuestionCategory.Tentacles && it.withinMeters?.toInt() == rangeMeters
        }
        PresetChip(
            label = "${rangeMeters}${stringResource(R.string.unit_meters_short)}",
            selected = state.withinMeters == rangeMeters,
            onClick = { actions.onSetWithinMeters(rangeMeters) },
            isRepeat = rangeRepeatCount > 0,
        )
    }
    val featureTypes = availableFeatureTypes(QuestionCategory.Tentacles, gameSize)
    TentaclesFeatureTypeChips(featureTypes, state, actions, askedQuestions)
    if (state.mode == QuestionSheetMode.Preview) {
        SimAnswerToggle(
            option1 = R.string.sim_nearest to SimAnswer.Nearest,
            option2 = R.string.sim_none to SimAnswer.None,
            selected = state.answer,
            onSelect = actions.onSetAnswer,
        )
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun TentaclesFeatureTypeChips(
    featureTypes: List<FeatureType>,
    state: SimulationState,
    actions: SimulationActions,
    askedQuestions: List<AskedQuestion>,
) {
    FlowRow(
        horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
        verticalArrangement = Arrangement.spacedBy(Spacing.xs),
    ) {
        featureTypes.forEach { ft ->
            val ftRepeatCount = askedQuestions.count {
                it.category == QuestionCategory.Tentacles && it.featureType?.wireValue == ft.wireValue
            }
            PresetChip(
                label = stringResource(ft.labelRes),
                selected = state.featureType == ft.wireValue,
                onClick = { actions.onSetFeatureType(ft.wireValue) },
                isRepeat = ftRepeatCount > 0,
            )
        }
    }
}

private fun simCanAsk(state: SimulationState): Boolean = when {
    state.outstandingQuestion != null || state.isSubmitting -> false
    state.mode == QuestionSheetMode.Ask && state.askingBlocked -> false
    state.mode == QuestionSheetMode.Ask -> simCanAskForCategory(state)
    state.seeker == null || state.answer == null -> false
    else -> simCanAskForCategory(state)
}

private fun simCanAskForCategory(state: SimulationState): Boolean = when (state.category) {
    QuestionCategory.Radar -> state.radiusMeters != null
    // Ask-mode thermometer submits via its own Start/Confirm buttons, never the bottom Ask button.
    QuestionCategory.Thermometer -> state.mode == QuestionSheetMode.Preview && state.end != null
    QuestionCategory.Measuring -> state.featureType != null || state.seaLevelSelected
    QuestionCategory.Matching -> simCanAskMatching(state)
    QuestionCategory.Tentacles -> state.withinMeters != null && state.featureType != null
    QuestionCategory.Photos -> state.mode == QuestionSheetMode.Ask && state.photoTarget != null
    else -> false
}

private fun simCanAskMatching(state: SimulationState): Boolean = when {
    state.stationNameLengthSelected -> true
    state.transitLineSelected -> state.selectedTransitLine != null
    else -> state.featureType != null
}

@Composable
private fun OutstandingQuestionCard(
    outstanding: AskedQuestion,
    state: SimulationState,
    actions: SimulationActions,
    edition: Edition,
) {
    val travelingThermometer = state.travelingThermometer
    val traveling = travelingThermometer != null
    val gpsAvailable = state.thermometerTraveledMeters != null
    val distanceMet = gpsAvailable &&
        (state.thermometerTraveledMeters ?: 0.0) >= (travelingThermometer?.distanceMeters ?: 0.0)
    Surface(
        modifier = Modifier.fillMaxWidth(),
        shape = MaterialTheme.shapes.small,
        color = MaterialTheme.colorScheme.secondaryContainer,
    ) {
        Column(
            modifier = Modifier.padding(Spacing.md),
            verticalArrangement = Arrangement.spacedBy(Spacing.sm),
        ) {
            Text(
                text = categoryLabel(outstanding.category),
                style = MaterialTheme.typography.labelLarge,
            )
            Text(
                text = stringResource(
                    if (traveling) R.string.question_thermometer_traveling_status
                    else R.string.question_outstanding_pending,
                ),
                style = MaterialTheme.typography.bodyMedium,
                color = MaterialTheme.colorScheme.onSurfaceVariant,
            )
            if (traveling) {
                Button(
                    onClick = actions.onConfirmThermometerArrival,
                    enabled = !state.isSubmitting && (distanceMet || !gpsAvailable),
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text(travelingThermometerButtonLabel(
                        distanceMet, gpsAvailable, state.thermometerTraveledMeters,
                        travelingThermometer?.distanceMeters, edition,
                    ))
                }
            }
            OutlinedButton(
                onClick = actions.onCancelQuestion,
                enabled = !state.isSubmitting,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text(stringResource(R.string.question_cancel_button))
            }
        }
    }
}

@Composable
private fun travelingThermometerButtonLabel(
    distanceMet: Boolean,
    gpsAvailable: Boolean,
    traveled: Double?,
    required: Double?,
    edition: Edition,
): String {
    if (distanceMet || !gpsAvailable) return stringResource(R.string.question_thermometer_confirm_button)
    val remaining = ((required ?: 0.0) - (traveled ?: 0.0)).coerceAtLeast(0.0)
    return stringResource(R.string.question_thermometer_remaining, formatDistance(remaining, edition))
}

@Composable
private fun PreviewToggle(isPreview: Boolean, onToggle: () -> Unit) {
    OutlinedButton(
        onClick = onToggle,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Text(
            stringResource(
                if (isPreview) R.string.sim_ask_toggle else R.string.sim_preview_toggle,
            ),
        )
    }
}

@Composable
internal fun categoryLabel(category: QuestionCategory): String = when (category) {
    QuestionCategory.Radar -> stringResource(R.string.question_category_radar)
    QuestionCategory.Thermometer -> stringResource(R.string.question_category_thermometer)
    QuestionCategory.Measuring -> stringResource(R.string.question_category_measuring)
    QuestionCategory.Matching -> stringResource(R.string.question_category_matching)
    QuestionCategory.Tentacles -> stringResource(R.string.question_category_tentacles)
    QuestionCategory.Photos -> stringResource(R.string.question_category_photos)
}

private data class NarrowingInfo(
    val pct: Int,
    val currentKm2: Double,
    val projectedKm2: Double,
)

private fun simNarrowing(currentKm2: Double?, projectedKm2: Double?): NarrowingInfo? {
    if (currentKm2 == null || projectedKm2 == null || currentKm2 <= 0.0) return null
    val pct = ((1.0 - projectedKm2 / currentKm2) * NARROWING_PCT_MULTIPLIER)
        .toInt().coerceIn(NARROWING_PCT_MIN, NARROWING_PCT_MAX)
    return NarrowingInfo(
        pct = pct,
        currentKm2 = currentKm2,
        projectedKm2 = projectedKm2,
    )
}

@Composable
private fun areaLabel(km2: Double): String = when {
    km2 >= AREA_KM2_LARGE_THRESHOLD -> "%.1f k %s".format(
        km2 / AREA_KM2_LARGE_THRESHOLD,
        stringResource(R.string.unit_square_kilometers),
    )
    km2 >= AREA_KM2_SMALL_THRESHOLD -> "%.0f %s".format(km2, stringResource(R.string.unit_square_kilometers))
    else -> "%.1f %s".format(km2, stringResource(R.string.unit_square_kilometers))
}

private fun repeatCountFor(state: SimulationState, askedQuestions: List<AskedQuestion>): Int {
    if (askedQuestions.isEmpty()) return 1
    val matchCount = askedQuestions.count { q ->
        q.category == state.category &&
            when (state.category) {
                QuestionCategory.Radar -> {
                    if (state.isCustomRadius) q.isCustomRadius
                    else q.radiusMeters?.roundToInt() == state.radiusMeters
                }
                QuestionCategory.Thermometer -> q.distanceMeters?.roundToInt() == state.distanceMeters
                QuestionCategory.Measuring -> q.featureType?.wireValue == state.featureType
                QuestionCategory.Matching -> q.featureType?.wireValue == state.featureType
                QuestionCategory.Tentacles ->
                    q.featureType?.wireValue == state.featureType &&
                        q.withinMeters?.roundToInt() == state.withinMeters
                QuestionCategory.Photos -> q.photoTarget == state.photoTarget
                else -> false
            }
    }
    return matchCount + 1
}

@Composable
private fun RepeatConfirmDialog(
    show: Boolean,
    category: QuestionCategory,
    repeatCount: Int,
    onConfirm: () -> Unit,
    onDismiss: () -> Unit,
) {
    if (!show) return
    val (draw, keep) = cardEconomy.getValue(category)
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(stringResource(R.string.repeat_question_title)) },
        text = {
            Text(
                stringResource(
                    R.string.repeat_question_body,
                    draw,
                    keep,
                    repeatCount,
                ),
            )
        },
        confirmButton = {
            TextButton(onClick = {
                onDismiss()
                onConfirm()
            }) {
                Text(stringResource(R.string.repeat_question_confirm))
            }
        },
        dismissButton = {
            TextButton(onClick = onDismiss) {
                Text(stringResource(R.string.repeat_question_cancel))
            }
        },
    )
}

@Preview(showBackground = true)
@Composable
private fun SimulationSheetPreview() {
    MaterialTheme {
        SimulationSheet(
            state = SimulationState(
                category = QuestionCategory.Radar,
                mode = QuestionSheetMode.Preview,
                seeker = ZonePin(48.8566, 2.3522),
                radiusMeters = 1000,
                answer = SimAnswer.Inside,
                currentAreaKm2 = 100.0,
                projectedAreaKm2 = 45.0,
            ),
            edition = Edition.Metric,
            gameSize = GameSize.Medium,
            actions = SimulationActions(),
            askedQuestions = emptyList(),
        )
    }
}
