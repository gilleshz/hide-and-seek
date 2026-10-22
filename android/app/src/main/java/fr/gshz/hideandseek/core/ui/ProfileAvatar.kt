package fr.gshz.hideandseek.core.ui

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.tooling.preview.Preview
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import coil3.compose.AsyncImage
import fr.gshz.hideandseek.R
import fr.gshz.hideandseek.core.ui.theme.AppTheme
import fr.gshz.hideandseek.core.ui.theme.BrandColors

private const val INITIALS_SIZE_RATIO = 0.34f

/** The circle the map draws: a round crop with a white ring, or initials when there is no photo. */
@Composable
fun ProfileAvatar(
    name: String?,
    avatarUrl: String?,
    modifier: Modifier = Modifier,
    size: Dp = 56.dp,
) {
    val outline = MaterialTheme.colorScheme.outlineVariant
    Box(modifier = modifier.size(size), contentAlignment = Alignment.Center) {
        Box(
            modifier = Modifier
                .fillMaxSize()
                .clip(CircleShape)
                .background(BrandColors.selfMarker),
            contentAlignment = Alignment.Center,
        ) {
            Text(
                text = initialsOf(name),
                color = Color.White,
                fontSize = (size.value * INITIALS_SIZE_RATIO).sp,
                style = MaterialTheme.typography.titleMedium,
            )
            avatarUrl?.let { url ->
                AsyncImage(
                    model = url,
                    contentDescription = stringResource(R.string.settings_profile_photo),
                    contentScale = ContentScale.Crop,
                    modifier = Modifier.fillMaxSize(),
                )
            }
        }
        Canvas(modifier = Modifier.fillMaxSize()) {
            val edge = this.size.minDimension / 2f
            drawCircle(
                color = outline,
                radius = edge - 0.5.dp.toPx(),
                style = Stroke(width = 1.dp.toPx()),
            )
            drawCircle(
                color = Color.White,
                radius = edge - 2.dp.toPx(),
                style = Stroke(width = 2.dp.toPx()),
            )
        }
    }
}

internal fun initialsOf(name: String?): String =
    name.orEmpty()
        .split(" ")
        .take(2)
        .mapNotNull { it.firstOrNull()?.uppercaseChar() }
        .joinToString("")

@Preview
@Composable
private fun ProfileAvatarPreview() {
    AppTheme {
        Box(modifier = Modifier.size(120.dp)) {
            ProfileAvatar(
                name = "Gilles Hertz",
                avatarUrl = null,
                modifier = Modifier.align(Alignment.Center),
            )
        }
    }
}
