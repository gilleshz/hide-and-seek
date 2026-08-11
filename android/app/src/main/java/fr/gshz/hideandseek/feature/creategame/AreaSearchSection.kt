package fr.gshz.hideandseek.feature.creategame

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Search
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.unit.dp
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.SectionHeader
import fr.gshz.hideandseek.core.ui.theme.Spacing
import fr.gshz.hideandseek.domain.repository.AreaInfo

@Composable
internal fun AreaSearchSection(uiState: CreateGameUiState, actions: CreateGameActions) {
    Column(verticalArrangement = Arrangement.spacedBy(Spacing.sm)) {
        SectionHeader(text = stringResource(R.string.area_search_game_boundary), icon = Icons.Filled.Search)
        AreaSearchInputRow(uiState, actions)
        if (uiState.selectedAreas.isNotEmpty()) {
            Text(
                stringResource(
                    R.string.area_search_selected,
                    uiState.selectedAreas.joinToString { it.displayName.take(30) },
                ),
                style = MaterialTheme.typography.bodySmall,
            )
        }
        if (uiState.areaSearchResults.isNotEmpty()) {
            AreaSearchResultList(uiState.areaSearchResults, uiState.selectedAreas, actions.onToggleArea)
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
                selected = selectedAreas.any { it.osmId == area.osmId },
                onClick = { onToggle(area) },
            )
        }
    }
}

@Composable
private fun AreaChip(label: String, selected: Boolean, onClick: () -> Unit) {
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
            Text(
                text = label,
                style = MaterialTheme.typography.bodyMedium,
                maxLines = 2,
                modifier = Modifier.weight(1f),
            )
        }
    }
}
