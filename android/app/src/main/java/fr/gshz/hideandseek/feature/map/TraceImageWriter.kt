package fr.gshz.hideandseek.feature.map

import android.content.Context
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Path
import android.net.Uri
import androidx.core.content.FileProvider
import dagger.hilt.android.qualifiers.ApplicationContext
import java.io.File
import java.io.FileOutputStream
import java.io.IOException
import java.util.UUID
import java.util.concurrent.atomic.AtomicReference
import javax.inject.Inject
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/** Turns the traced streets into the photo the hider answers with. */
interface TraceImageWriter {
    suspend fun write(polylines: List<List<ZonePin>>): Uri
}

/**
 * The answer must be a drawing of the street, not a screenshot of the map: white sheet, black line,
 * nothing else. North is up because [projectTrace] flips latitude, so the map's bearing is ignored.
 */
class AndroidTraceImageWriter @Inject constructor(
    @ApplicationContext private val context: Context,
) : TraceImageWriter {

    // Only what this write supersedes, plus pre-instance leftovers: never a file another write is still filling.
    private val writtenFile = AtomicReference<File?>(null)
    private val startedAtMillis = System.currentTimeMillis()

    override suspend fun write(polylines: List<List<ZonePin>>): Uri = withContext(Dispatchers.Default) {
        val file = newTraceFile()
        try {
            render(file, polylines)
        } catch (e: IOException) {
            file.delete()
            throw e
        }
        writtenFile.getAndSet(file)?.delete()
        FileProvider.getUriForFile(context, "${context.packageName}.fileprovider", file)
    }

    private fun render(file: File, polylines: List<List<ZonePin>>) {
        val bitmap = Bitmap.createBitmap(IMAGE_WIDTH, IMAGE_HEIGHT, Bitmap.Config.ARGB_8888)
        try {
            drawTrace(bitmap, polylines)
            FileOutputStream(file).use { output -> bitmap.compress(Bitmap.CompressFormat.PNG, PNG_QUALITY, output) }
        } finally {
            bitmap.recycle()
        }
    }

    private fun newTraceFile(): File {
        val directory = File(context.cacheDir, TRACE_DIRECTORY).apply { mkdirs() }
        directory.listFiles()?.filter { it.lastModified() < startedAtMillis }?.forEach { it.delete() }
        return File(directory, "trace-${UUID.randomUUID()}.png")
    }

    private fun drawTrace(bitmap: Bitmap, polylines: List<List<ZonePin>>) {
        val canvas = Canvas(bitmap)
        canvas.drawColor(Color.WHITE)
        // One shared bounds, then each street its own subpath, so disjoint streets keep their layout.
        val projected = projectTracePaths(polylines, IMAGE_WIDTH, IMAGE_HEIGHT, PADDING_FRACTION)
        val path = Path()
        projected.filter { it.size >= MIN_LINE_POINTS }.forEach { line ->
            path.moveTo(line.first().x, line.first().y)
            line.drop(1).forEach { path.lineTo(it.x, it.y) }
        }
        if (path.isEmpty) return
        canvas.drawPath(path, tracePaint())
    }

    private fun tracePaint(): Paint = Paint().apply {
        isAntiAlias = true
        color = Color.BLACK
        style = Paint.Style.STROKE
        strokeWidth = STROKE_WIDTH_PX
        strokeCap = Paint.Cap.ROUND
        strokeJoin = Paint.Join.ROUND
    }

    private companion object {
        const val TRACE_DIRECTORY = "traces"
        const val MIN_LINE_POINTS = 2
        const val IMAGE_WIDTH = 1200
        const val IMAGE_HEIGHT = 900
        const val PADDING_FRACTION = 0.08f
        const val STROKE_WIDTH_PX = 6f
        const val PNG_QUALITY = 100
    }
}
