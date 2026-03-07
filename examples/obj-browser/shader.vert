attribute vec3 a_position;
attribute vec3 a_normal;
attribute vec2 a_uv;

uniform mat4 u_model;
uniform mat4 u_view;
uniform mat4 u_projection;

varying vec3 v_normal;
varying vec2 v_uv;

void main()
{
    vec4 world = u_model * vec4(a_position, 1.0);
    v_normal = normalize((u_model * vec4(a_normal, 0.0)).xyz);
    v_uv = a_uv;
    gl_Position = u_projection * u_view * world;
}
