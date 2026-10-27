package fr.gshz.hideandseek.feature.creategame

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.SectionHeader
import fr.gshz.hideandseek.core.ui.resolve
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.repository.AreaInfo
import java.text.NumberFormat
import kotlin.math.roundToInt

@Composable
internal fun AreaSearchSection(
    uiState: CreateGameUiState,
    actions: CreateGameActions,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier,
        verticalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        SectionHeader(text = stringResource(R.string.area_search_game_boundary), icon = Icons.Filled.Search)
        if (uiState.showFitArea) {
            LineBoundaryToggle(
                checked = uiState.useLineBoundary,
                onCheckedChange = actions.onLineBoundaryChange,
            )
            if (uiState.useLineBoundary && (uiState.isLoadingBoundary || uiState.boundaryError != null)) {
                LineBoundaryStatus(uiState)
            }
        }
        AreaSearchInputRow(uiState, actions)
        if (uiState.selectedAreas.isNotEmpty()) {
            SelectedAreaChips(uiState.selectedAreas, actions.onToggleArea)
        }
        if (uiState.areaSearchResults.isNotEmpty()) {
            AreaSearchResultList(uiState.areaSearchResults, uiState.selectedAreas, actions.onToggleArea)
        }
    }
}

@Composable
private fun LineBoundaryToggle(checked: Boolean, onCheckedChange: (Boolean) -> Unit) {
    Row(
        modifier = Modifier
            .fillMaxWidth()
            .toggleable(value = checked, role = Role.Checkbox, onValueChange = onCheckedChange),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Checkbox(checked = checked, onCheckedChange = null)
        Spacer(Modifier.width(Spacing.sm))
        Text(
            text = stringResource(R.string.area_fit_toggle),
            style = MaterialTheme.typography.bodyMedium,
        )
    }
}

@Composable
private fun LineBoundaryStatus(uiState: CreateGameUiState) {
    if (uiState.isLoadingBoundary) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            CircularProgressIndicator(modifier = Modifier.size(18.dp), strokeWidth = 2.dp)
            Spacer(Modifier.width(Spacing.sm))
            Text(
                text = stringResource(R.string.area_fit_loading),
                style = MaterialTheme.typography.bodySmall,
            )
        }
    }
    uiState.boundaryError?.let { error ->
        Text(
            text = error.resolve(),
            style = MaterialTheme.typography.bodySmall,
            color = MaterialTheme.colorScheme.error,
        )
    }
}

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun SelectedAreaChips(areas: List<AreaInfo>, onToggle: (AreaInfo) -> Unit) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
        Text(
            pluralStringResource(R.plurals.area_search_selected_count, areas.size, areas.size),
            style = MaterialTheme.typography.bodySmall,
        )
        FlowRow(
            horizontalArrangement = Arrangement.spacedBy(Spacing.xs),
            verticalArrangement = Arrangement.spacedBy(Spacing.xs),
        ) {
            areas.forEach { area ->
                FilterChip(
                    selected = true,
                    onClick = { onToggle(area) },
                    label = { Text(area.displayName.substringBefore(',').trim()) },
                )
            }
        }
    }
}

@Composable
private fun AreaSearchInputRow(uiState: CreateGameUiState, actions: CreateGameActions) {
    Row(
        modifier = Modifier.fillMaxWidth(),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(Spacing.sm),
    ) {
        OutlinedTextField(
            value = uiState.areaSearchQuery,
            onValueChange = actions.onAreaSearchQueryChange,
            label = { Text(stringResource(R.string.area_search_hint)) },
            modifier = Modifier.weight(1f),
            singleLine = true,
            keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
            keyboardActions = KeyboardActions(onSearch = { actions.onSearchAreasClick() }),
        )
        IconButton(
            onClick = actions.onSearchAreasClick,
            enabled = !uiState.isSearchingAreas && uiState.areaSearchQuery.isNotBlank(),
        ) {
            if (uiState.isSearchingAreas) {
                CircularProgressIndicator(modifier = Modifier.size(20.dp), strokeWidth = 2.dp)
            } else {
                Icon(Icons.Filled.Search, contentDescription = stringResource(R.string.area_search_button))
            }
        }
    }
}

@Composable
private fun AreaSearchResultList(
    results: List<AreaInfo>,
    selectedAreas: List<AreaInfo>,
    onToggle: (AreaInfo) -> Unit,
) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.xs)) {
        results.forEach { area ->
            AreaChip(
                label = area.displayName.take(80),
                detail = areaDetail(area),
                selected = selectedAreas.any { it.osmId == area.osmId },
                onClick = { onToggle(area) },
            )
        }
    }
}

/** Null when Nominatim returned neither a bounding box nor a population. */
@Composable
private fun areaDetail(area: AreaInfo): String? {
    val width = area.widthKm
    val height = area.heightKm
    val size = if (width != null && height != null) {
        stringResource(R.string.area_search_size_km, width.roundToInt(), height.roundToInt())
    } else {
        null
    }
    val population = area.population
    val people = if (population != null) {
        stringResource(
            R.string.area_search_population,
            NumberFormat.getIntegerInstance().format(population),
        )
    } else {
        null
    }

    return listOfNotNull(size, people).joinToString(" · ").ifEmpty { null }
}

@Composable
private fun AreaChip(label: String, detail: String?, selected: Boolean, onClick: () -> Unit) {
    Surface(
        onClick = onClick,
        shape = MaterialTheme.shapes.small,
        color = if (selected) MaterialTheme.colorScheme.primaryContainer
        else MaterialTheme.colorScheme.surfaceContainerHigh,
        modifier = Modifier.fillMaxWidth(),
    ) {
        Row(
            modifier = Modifier.padding(horizontal = Spacing.md, vertical = Spacing.sm),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            if (selected) {
                Icon(
                    Icons.Filled.Check,
                    contentDescription = null,
                    modifier = Modifier.size(18.dp),
                    tint = MaterialTheme.colorScheme.onPrimaryContainer,
                )
                Spacer(Modifier.width(Spacing.sm))
            }
            Column(modifier = Modifier.weight(1f)) {
                Text(
                    text = label,
                    style = MaterialTheme.typography.bodyMedium,
                    maxLines = 2,
                )
                if (detail != null) {
                    Text(
                        text = detail,
                        style = MaterialTheme.typography.bodySmall,
                        color = MaterialTheme.colorScheme.onSurfaceVariant,
                    )
                }
            }
        }
    }
}
